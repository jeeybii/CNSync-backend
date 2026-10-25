import argparse
import json
import os
import re
import time
import urllib.error
import urllib.request
from pathlib import Path

import torch
from transformers import T5ForConditionalGeneration, T5Tokenizer

from trial import MODEL_NAME, format_prompt


DEFAULT_CONTEXT = (
    "A router forwards packets between different networks using destination IP addresses "
    "and a routing table. A switch connects devices within the same local network and "
    "forwards frames using MAC addresses. Routers separate broadcast domains, while "
    "switches create a separate collision domain for each port."
)

CASES = (
    ("remembering", "identification", "easy", "short answer"),
    ("understanding", "true_false", "medium", "true or false question"),
    ("applying", "multiple_choice", "medium", "multiple choice question"),
    ("analyzing", "essay", "hard", "short answer"),
)


def load_env(path: Path) -> None:
    if not path.exists():
        return
    for raw_line in path.read_text().splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def generate_seed(tokenizer, model, context: str, source_type: str, difficulty: str) -> str:
    inputs = tokenizer(
        format_prompt(source_type, difficulty, context),
        return_tensors="pt",
        max_length=512,
        truncation=True,
    )
    with torch.inference_mode():
        output = model.generate(
            **inputs,
            max_new_tokens=150,
            num_beams=4,
            no_repeat_ngram_size=2,
            early_stopping=True,
        )
    return tokenizer.decode(output[0], skip_special_tokens=True)


def assistant_prompt(context: str, seed: str, bloom: str, question_type: str) -> str:
    return f"""You are refining a candidate assessment question for CNSync.

Use only facts supported by the learning-material context. Rewrite the Avinash T5 seed so it genuinely targets Bloom's {bloom.title()} level and uses the requested type: {question_type}.

Context:
{context}

Avinash T5 seed:
{seed}

Return only one JSON object with these keys:
- question
- question_type (exactly {question_type})
- bloom_level (exactly {bloom})
- choices (four strings for multiple_choice, otherwise [])
- answer
- explanation

    For true_false, answer must be "True" or "False". For identification, keep the expected answer concise. For essay, provide a concise scoring-guide answer. Do not invent unsupported details."""


def gemma_only_prompt(context: str, bloom: str, question_type: str) -> str:
    return f"""You create grounded assessment questions for CNSync.

Using only the learning-material context below, generate one question that genuinely targets Bloom's {bloom.title()} level and uses the requested type: {question_type}.

Context:
{context}

Return only one JSON object with these keys:
- question
- question_type (exactly {question_type})
- bloom_level (exactly {bloom})
- choices (four strings for multiple_choice, otherwise [])
- answer
- explanation

For true_false, answer must be "True" or "False". For identification, keep the expected answer concise. For essay, provide a concise scoring-guide answer. Use plausible distractors for multiple choice, but do not introduce facts that are required to answer the question unless those facts appear in the context."""


def extract_json(text: str) -> dict:
    cleaned = re.sub(r"^```(?:json)?\s*|\s*```$", "", text.strip(), flags=re.I | re.S)
    start, end = cleaned.find("{"), cleaned.rfind("}")
    if start < 0 or end < start:
        raise ValueError(f"No JSON object returned: {text[:200]}")
    return json.loads(cleaned[start : end + 1])


def call_google_model(base_url: str, api_key: str, model: str, prompt: str) -> tuple[dict, float]:
    root = base_url.rstrip("/")
    if not root.endswith("/v1beta"):
        root += "/v1beta"
    url = f"{root}/models/{model}:generateContent"
    generation_config = {"temperature": 0.2, "maxOutputTokens": 4096}
    if model.startswith("gemini-2.5"):
        generation_config["thinkingConfig"] = {"thinkingBudget": 0}
    elif model.startswith("gemma-4"):
        generation_config["thinkingConfig"] = {"thinkingLevel": "minimal"}
    body = json.dumps({
        "contents": [{"parts": [{"text": prompt}]}],
        "generationConfig": generation_config,
    }).encode()
    request = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json", "x-goog-api-key": api_key})
    started = time.perf_counter()
    try:
        with urllib.request.urlopen(request, timeout=180) as response:
            payload = json.load(response)
    except urllib.error.HTTPError as error:
        detail = error.read().decode(errors="replace")
        raise RuntimeError(f"{model} returned HTTP {error.code}: {detail[:500]}") from error
    elapsed = time.perf_counter() - started
    parts = payload["candidates"][0]["content"].get("parts", [])
    text = "\n".join(str(part.get("text", "")) for part in parts if part.get("text"))
    return extract_json(text), elapsed


def basic_checks(item: dict, context: str, requested_type: str, requested_bloom: str) -> dict:
    choices = item.get("choices")
    answer = str(item.get("answer", "")).strip()
    return {
        "has_question": bool(str(item.get("question", "")).strip()),
        "type_matches": item.get("question_type") == requested_type,
        "bloom_matches": item.get("bloom_level") == requested_bloom,
        "answer_present": bool(answer),
        "choice_shape_valid": (isinstance(choices, list) and len(choices) == 4)
        if requested_type == "multiple_choice"
        else choices == [],
        "answer_in_choices": requested_type != "multiple_choice" or answer in (choices or []),
        "has_explanation": bool(str(item.get("explanation", "")).strip()),
        "context_word_overlap": round(
            len(set(re.findall(r"[a-z]{4,}", str(item.get("question", "")).lower())) & set(re.findall(r"[a-z]{4,}", context.lower())))
            / max(1, len(set(re.findall(r"[a-z]{4,}", str(item.get("question", "")).lower())))),
            3,
        ),
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Compare Avinash T5 assisted by Gemini and Gemma.")
    parser.add_argument("--context", default=DEFAULT_CONTEXT)
    parser.add_argument("--env-file", default="../../CNSync-backend/.env")
    parser.add_argument("--gemini-model", default=None)
    parser.add_argument("--gemma-model", default="gemma-4-26b-a4b-it")
    parser.add_argument("--output", default="comparison-results.json")
    parser.add_argument("--reuse-seeds", action="store_true", help="Reuse Avinash seeds from the existing output file.")
    parser.add_argument("--gemma-only", action="store_true", help="Skip Avinash and Gemini; test direct Gemma generation.")
    args = parser.parse_args()

    load_env((Path(__file__).parent / args.env_file).resolve())
    api_key = os.environ.get("GEMINI_API_KEY", "")
    if not api_key:
        raise SystemExit("GEMINI_API_KEY is missing.")
    base_url = os.environ.get("GEMINI_BASE_URL", "https://generativelanguage.googleapis.com/v1beta")
    gemini_model = args.gemini_model or os.environ.get("GEMINI_MODEL", "gemini-2.5-flash")

    output = Path(__file__).parent / args.output
    saved_seeds = {}
    if args.reuse_seeds and output.exists():
        previous = json.loads(output.read_text())
        saved_seeds = {(row["bloom"], row["question_type"]): row["avinash_seed"] for row in previous.get("results", [])}

    tokenizer = t5 = None
    if not args.gemma_only and len(saved_seeds) < len(CASES):
        print(f"Loading {MODEL_NAME}...")
        tokenizer = T5Tokenizer.from_pretrained(MODEL_NAME)
        t5 = T5ForConditionalGeneration.from_pretrained(MODEL_NAME)
        t5.eval()

    results = []
    for bloom, question_type, difficulty, source_type in CASES:
        print(f"\nGenerating shared seed: {bloom} / {question_type}")
        seed = saved_seeds.get((bloom, question_type), "")
        if not args.gemma_only and not seed:
            seed = generate_seed(tokenizer, t5, args.context, source_type, difficulty)
        prompt = gemma_only_prompt(args.context, bloom, question_type) if args.gemma_only else assistant_prompt(args.context, seed, bloom, question_type)
        row = {"bloom": bloom, "question_type": question_type, "avinash_seed": seed, "assistants": {}}
        providers = (("gemma_only", args.gemma_model),) if args.gemma_only else (("gemini", gemini_model), ("gemma", args.gemma_model))
        for label, model_name in providers:
            print(f"  Asking {label} ({model_name})...")
            try:
                item, elapsed = call_google_model(base_url, api_key, model_name, prompt)
                row["assistants"][label] = {
                    "model": model_name,
                    "latency_seconds": round(elapsed, 2),
                    "output": item,
                    "checks": basic_checks(item, args.context, question_type, bloom),
                }
            except Exception as error:
                row["assistants"][label] = {"model": model_name, "error": str(error)}
        results.append(row)

    output.write_text(json.dumps({"context": args.context, "results": results}, indent=2))
    print(f"\nSaved comparison to {output}")


if __name__ == "__main__":
    main()
