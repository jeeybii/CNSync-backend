<?php

namespace App\Enums;

enum UserRole: string
{
    case Faculty = 'faculty';
    case Student = 'student';
    case Admin = 'admin';
}
