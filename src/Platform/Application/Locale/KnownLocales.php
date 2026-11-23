<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Locale;

/**
 * The locales Kadupul knows, in the order the legacy rules search them.
 *
 * include/global_languages.php returns this table from get_list_of_locales().
 * A locale here need not ship a catalog: zh-HK is known but has none, so it is
 * shown in English. A bare language picks the first listed region (pt-PT
 * before pt-BR), so the order is part of the contract.
 *
 * Kept free of other classes: the legacy bootstrap requires it before the
 * Composer autoloader runs.
 */
final class KnownLocales
{
    /** @var array<string, array{language: string, direction: string, country: string, filename: string}> */
    public const ALL = [
        'af-ZA' => ['language' => 'Afrikaans', 'direction' => 'ltr', 'country' => 'za', 'filename' => 'afrikaans_south_africa'],
        'sq-AL' => ['language' => 'Albanian', 'direction' => 'ltr', 'country' => 'al', 'filename' => 'albanian_albania'],
        'am-ET' => ['language' => 'Amharic', 'direction' => 'ltr', 'country' => 'et', 'filename' => 'amharic_ethiopia'],
        'ar-SA' => ['language' => 'Arabic', 'direction' => 'rtl', 'country' => 'sa', 'filename' => 'arabic_saudi_arabia'],
        'hy-AM' => ['language' => 'Armenian', 'direction' => 'ltr', 'country' => 'am', 'filename' => 'armenian_armenia'],
        'be-BY' => ['language' => 'Belarusian', 'direction' => 'ltr', 'country' => 'by', 'filename' => 'belarusian_belarus'],
        'bn-BD' => ['language' => 'Bengali', 'direction' => 'ltr', 'country' => 'bd', 'filename' => 'bengali_bangladesh'],
        'bg-BG' => ['language' => 'Bulgarian', 'direction' => 'ltr', 'country' => 'bg', 'filename' => 'bulgarian_bulgaria'],
        'zh-CN' => ['language' => 'Chinese (China)', 'direction' => 'ltr', 'country' => 'cn', 'filename' => 'chinese_china_simplified'],
        'zh-HK' => ['language' => 'Chinese (Hong Kong)', 'direction' => 'ltr', 'country' => 'hk', 'filename' => 'chinese_hong_kong'],
        'zh-SG' => ['language' => 'Chinese (Singapore)', 'direction' => 'ltr', 'country' => 'sg', 'filename' => 'chinese_singapore'],
        'zh-TW' => ['language' => 'Chinese (Taiwan)', 'direction' => 'ltr', 'country' => 'tw', 'filename' => 'chinese_taiwan'],
        'hr-HR' => ['language' => 'Croatian', 'direction' => 'ltr', 'country' => 'hr', 'filename' => 'croatian_croatia'],
        'cs-GZ' => ['language' => 'Czech', 'direction' => 'ltr', 'country' => 'cz', 'filename' => 'czech_czech_republic'],
        'da-DK' => ['language' => 'Danish', 'direction' => 'ltr', 'country' => 'dk', 'filename' => 'danish_denmark'],
        'nl-NL' => ['language' => 'Dutch', 'direction' => 'ltr', 'country' => 'nl', 'filename' => 'dutch_netherlands'],
        'en-US' => ['language' => 'English', 'direction' => 'ltr', 'country' => 'us', 'filename' => 'english_usa'],
        'en-GB' => ['language' => 'English (Britain)', 'direction' => 'ltr', 'country' => 'gb', 'filename' => 'english_gb'],
        'et-EE' => ['language' => 'Estonian', 'direction' => 'ltr', 'country' => 'ee', 'filename' => 'estonian_estonia'],
        'fi-FI' => ['language' => 'Finnish', 'direction' => 'ltr', 'country' => 'fi', 'filename' => 'finnish_finland'],
        'fr-FR' => ['language' => 'French', 'direction' => 'ltr', 'country' => 'fr', 'filename' => 'french_france'],
        'fr-SN' => ['language' => 'French (Africa)', 'direction' => 'ltr', 'country' => 'sn', 'filename' => 'french_africa'],
        'ka-GE' => ['language' => 'Georgian', 'direction' => 'ltr', 'country' => 'ge', 'filename' => 'georgian_georgia'],
        'de-DE' => ['language' => 'German', 'direction' => 'ltr', 'country' => 'de', 'filename' => 'german_germany'],
        'el-GR' => ['language' => 'Greek', 'direction' => 'ltr', 'country' => 'gr', 'filename' => 'greek_greece'],
        'ha-NG' => ['language' => 'Hausa', 'direction' => 'ltr', 'country' => 'ng', 'filename' => 'hausa_nigeria'],
        'he-IL' => ['language' => 'Hebrew', 'direction' => 'rtl', 'country' => 'il', 'filename' => 'hebrew_israel'],
        'hi-IN' => ['language' => 'Hindi', 'direction' => 'ltr', 'country' => 'in', 'filename' => 'hindi_india'],
        'hu-HU' => ['language' => 'Hungarian', 'direction' => 'ltr', 'country' => 'hu', 'filename' => 'hungarian_hungary'],
        'is-IS' => ['language' => 'Icelandic', 'direction' => 'ltr', 'country' => 'is', 'filename' => 'icelandic_iceland'],
        'ig-NG' => ['language' => 'Igbo', 'direction' => 'ltr', 'country' => 'ng', 'filename' => 'igbo_nigeria'],
        'id-ID' => ['language' => 'Indonesian', 'direction' => 'ltr', 'country' => 'id', 'filename' => 'indonesian_indonesia'],
        'ga-IE' => ['language' => 'Irish', 'direction' => 'ltr', 'country' => 'ie', 'filename' => 'irish_ireland'],
        'it-IT' => ['language' => 'Italian', 'direction' => 'ltr', 'country' => 'it', 'filename' => 'italian_italy'],
        'ja-JP' => ['language' => 'Japanese', 'direction' => 'ltr', 'country' => 'jp', 'filename' => 'japanese_japan'],
        'ko-KR' => ['language' => 'Korean', 'direction' => 'ltr', 'country' => 'kr', 'filename' => 'korean_korea'],
        'lv-LV' => ['language' => 'Latvian', 'direction' => 'ltr', 'country' => 'lv', 'filename' => 'latvian_latvia'],
        'lt-LT' => ['language' => 'Lithuanian', 'direction' => 'ltr', 'country' => 'lt', 'filename' => 'lithuanian_lithuania'],
        'mk-MK' => ['language' => 'Macedonian', 'direction' => 'ltr', 'country' => 'mk', 'filename' => 'macedonian_macedonia'],
        'ms-MY' => ['language' => 'Malay', 'direction' => 'ltr', 'country' => 'my', 'filename' => 'malay_malaysia'],
        'mt-LT' => ['language' => 'Maltese', 'direction' => 'ltr', 'country' => 'lt', 'filename' => 'maltese_malta'],
        'mr-IN' => ['language' => 'Marathi', 'direction' => 'ltr', 'country' => 'in', 'filename' => 'marathi_india'],
        'no-NO' => ['language' => 'Norwegian', 'direction' => 'ltr', 'country' => 'no', 'filename' => 'norwegian_norway'],
        'fa-IR' => ['language' => 'Persian', 'direction' => 'rtl', 'country' => 'ir', 'filename' => 'persian_iran'],
        'pl-PL' => ['language' => 'Polish', 'direction' => 'ltr', 'country' => 'pl', 'filename' => 'polish_poland'],
        'pt-PT' => ['language' => 'Portuguese', 'direction' => 'ltr', 'country' => 'pt', 'filename' => 'portuguese_portugal'],
        'pt-BR' => ['language' => 'Portuguese (Brazil)', 'direction' => 'ltr', 'country' => 'br', 'filename' => 'portuguese_brazil'],
        'ro-RO' => ['language' => 'Romanian', 'direction' => 'ltr', 'country' => 'ro', 'filename' => 'romanian_romania'],
        'ru-RU' => ['language' => 'Russian', 'direction' => 'ltr', 'country' => 'ru', 'filename' => 'russian_russia'],
        'sr-RS' => ['language' => 'Serbian', 'direction' => 'ltr', 'country' => 'rs', 'filename' => 'serbian_serbia'],
        'sk-SK' => ['language' => 'Slovak', 'direction' => 'ltr', 'country' => 'sk', 'filename' => 'slovak_slovakia'],
        'sl-SI' => ['language' => 'Slovenian', 'direction' => 'ltr', 'country' => 'si', 'filename' => 'slovenian_slovenia'],
        'es-ES' => ['language' => 'Spanish', 'direction' => 'ltr', 'country' => 'es', 'filename' => 'spanish_spain'],
        'sw-KE' => ['language' => 'Swahili', 'direction' => 'ltr', 'country' => 'ke', 'filename' => 'swahili_kenya'],
        'sv-SE' => ['language' => 'Swedish', 'direction' => 'ltr', 'country' => 'se', 'filename' => 'swedish_sweden'],
        'ta-IN' => ['language' => 'Tamil', 'direction' => 'ltr', 'country' => 'in', 'filename' => 'tamil_india'],
        'te-IN' => ['language' => 'Telugu', 'direction' => 'ltr', 'country' => 'in', 'filename' => 'telugu_india'],
        'th-TH' => ['language' => 'Thai', 'direction' => 'ltr', 'country' => 'th', 'filename' => 'thai_thailand'],
        'tr-TR' => ['language' => 'Turkish', 'direction' => 'ltr', 'country' => 'tr', 'filename' => 'turkish_turkey'],
        'uk-UA' => ['language' => 'Ukrainian', 'direction' => 'ltr', 'country' => 'ua', 'filename' => 'ukrainian_ukraine'],
        'ur-PK' => ['language' => 'Urdu', 'direction' => 'rtl', 'country' => 'pk', 'filename' => 'urdu_pakistan'],
        'vi-VN' => ['language' => 'Vietnamese', 'direction' => 'ltr', 'country' => 'vn', 'filename' => 'vietnamese_vietnam'],
        'xh-ZA' => ['language' => 'Xhosa', 'direction' => 'ltr', 'country' => 'za', 'filename' => 'xhosa_south_africa'],
        'yo-NG' => ['language' => 'Yoruba', 'direction' => 'ltr', 'country' => 'ng', 'filename' => 'yoruba_nigeria'],
        'zu-ZA' => ['language' => 'Zulu', 'direction' => 'ltr', 'country' => 'za', 'filename' => 'zulu_south_africa'],
    ];
}
