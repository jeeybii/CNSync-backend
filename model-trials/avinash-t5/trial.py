import argparse

import torch
from transformers import T5ForConditionalGeneration, T5Tokenizer


MODEL_NAME = "Avinash250325/T5BaseQuestionGeneration"
QUESTION_TYPES = (
    "short answer",
    "multiple choice question",
    "true or false question",
)
DIFFICULTIES = ("easy", "medium", "hard")

SAMPLE_CONTEXT = (
    "A router is a networking device that forwards data packets between "
    "different computer networks. It examines the destination IP address of "
    "each packet and uses a routing table to determine the best path. A switch, "
    "in contrast, primarily connects devices within the same local network and "
    "forwards frames using MAC addresses."
)


def format_prompt(question_type: str, difficulty: str, context: str, answer: str = "") -> str:
    answer_part = f"[{answer}]" if answer else ""
    return (
        f"<extra_id_97>{question_type} "
        f"<extra_id_98>{difficulty} "
        f"<extra_id_99>{answer_part} {context}"
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Try the Avinash T5 question generator locally.")
    parser.add_argument("--context", default=SAMPLE_CONTEXT, help="Learning-material passage.")
    parser.add_argument("--answer", default="", help="Optional answer to target.")
    parser.add_argument("--difficulty", choices=DIFFICULTIES, default="medium")
    parser.add_argument(
        "--type",
        choices=(*QUESTION_TYPES, "all"),
        default="all",
        help="Question type to generate.",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    print(f"Loading {MODEL_NAME}...")
    tokenizer = T5Tokenizer.from_pretrained(MODEL_NAME)
    model = T5ForConditionalGeneration.from_pretrained(MODEL_NAME)
    model.eval()

    question_types = QUESTION_TYPES if args.type == "all" else (args.type,)

    print(f"\nContext:\n{args.context}\n")
    for question_type in question_types:
        prompt = format_prompt(question_type, args.difficulty, args.context, args.answer)
        inputs = tokenizer(
            prompt,
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

        question = tokenizer.decode(output[0], skip_special_tokens=True)
        print(f"{question_type.title()} ({args.difficulty}):\n{question}\n")


if __name__ == "__main__":
    main()
