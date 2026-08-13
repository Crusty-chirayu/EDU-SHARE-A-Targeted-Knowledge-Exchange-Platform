<?php
declare(strict_types=1);

namespace EduShare\Modules\Profiles\Http;

use EduShare\Shared\Http\HttpException;

final class ContributorDirectoryInput
{
    public static function universityId(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new HttpException(422, 'A valid university ID is required.');
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new HttpException(422, 'A valid university ID is required.');
        }
        return $id;
    }
}
