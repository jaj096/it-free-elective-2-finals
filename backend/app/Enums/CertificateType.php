<?php

namespace App\Enums;

enum CertificateType: string
{
    case Enrollment = 'enrollment';
    case GoodMoral = 'good_moral';
    case Grades = 'grades';
    case Graduation = 'graduation';

    public function label(): string
    {
        return match ($this) {
            self::Enrollment => 'Certificate of Enrollment',
            self::GoodMoral => 'Certificate of Good Moral Character',
            self::Grades => 'Certification of Grades',
            self::Graduation => 'Certificate of Graduation',
        };
    }
}
