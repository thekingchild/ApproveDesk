<?php

namespace App\Enums;

enum RequestFieldType: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case RichText = 'rich_text';
    case Number = 'number';
    case Currency = 'currency';
    case Date = 'date';
    case DateTime = 'date_time';
    case Checkbox = 'checkbox';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Radio = 'radio';
    case User = 'user';
    case Department = 'department';
    case Url = 'url';
    case Email = 'email';
    case File = 'file';
    case Calculated = 'calculated';
    case Json = 'json';

    public function valueColumn(): string
    {
        return match ($this) {
            self::ShortText, self::Select, self::Radio, self::Url, self::Email => 'string_value',
            self::LongText, self::RichText => 'text_value',
            self::Number, self::Currency, self::Calculated => 'decimal_value',
            self::Date => 'date_value',
            self::DateTime => 'datetime_value',
            self::Checkbox => 'boolean_value',
            self::User => 'user_value_id',
            self::Department => 'department_value_id',
            self::MultiSelect, self::File, self::Json => 'json_value',
        };
    }
}
