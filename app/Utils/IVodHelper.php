<?php

namespace App\Utils;

class IVodHelper
{
    public static function getSubjects($meet_name) {
        $first_order_indexes = ['一、', '二、', '三、', '四、', '五、', '六、', '七、', '八、', '九、', '十、'];
        $content = self::parseReason(trim($meet_name));
        $with_first_order_index = mb_strpos($content, $first_order_indexes[0]) === 0;

        if (! $with_first_order_index) {
            $subjects = [];
            $subjects[] = $content;
        } else {
            $subjects = self::parseSubjects($content, $first_order_indexes);
        }

        return $subjects;
    }

    public static function digestSubjects($subjects)
    {
        $digested_subjects = array_map(function ($subject) {
            $digest = self::getBillSubject($subject);
            if (! $digest) {
                $digest = self::getI12nSubject($subject);
            }
            return $digest;
        }, $subjects);
        $merged_subjects = [];
        foreach ($digested_subjects as $idx => $metadata) {
            if (! $metadata) {
                $merged_subjects[] = ['polyfill', $subjects[$idx]];
                continue;
            }
            $subject_type = $metadata[0];
            if ($subject_type == 'i12n') {
                $merged_subjects[] = $metadata;
            }
            if ($subject_type == 'bill') {
                $isMerged = false;
                foreach ($merged_subjects as &$existing_metadata) {
                    if ($existing_metadata[1] == $metadata[1]) {
                        $existing_metadata[3] = $existing_metadata[3] + $metadata[3];
                        $isMerged = true;
                        break;
                    }
                }
                if (! $isMerged) {
                    $merged_subjects[] = $metadata;
                }
            }
        }
        $digested_subjects = array_map(function ($metadata) {
            $subject_type = $metadata[0];
            if (in_array($subject_type, ['i12n', 'polyfill'])) {
                return $metadata[1];
            }
            if ($subject_type == 'bill') {
                $law = $metadata[1];
                $law_type = $metadata[2];
                $bill_cnt = $metadata[3];
                if ($bill_cnt == 1) {
                    $result = sprintf("審查「%s」%s草案", $law, $law_type);
                } else {
                    $result = sprintf("併 %d 案審查「%s」%s草案", $bill_cnt, $law, $law_type);
                }
                return $result;
            }
            return 'error';
        }, $merged_subjects);
        return $digested_subjects;
    }

    public static function getLaws($subjects)
    {
        $bracket_pairs = ['「」', '（）', '《》', '『』'];
        $bracket_starters = array_map(fn ($pairs) => mb_substr($pairs, 0, 1), $bracket_pairs);
        $raw_texts = [];
        foreach($subjects as $subject) {
            $text_start_idx = null;
            $bracket_ender = null;
            foreach(mb_str_split($subject) as $char_idx => $char) {
                $bracket_idx = array_search($char, $bracket_starters);
                if ($bracket_idx !== false) {
                    $text_start_idx = $char_idx;
                    $bracket_ender = mb_substr($bracket_pairs[$bracket_idx], 1);
                    continue;
                }
                if ($bracket_ender == $char) {
                    $raw_texts[] = mb_substr($subject, $text_start_idx + 1, $char_idx - ($text_start_idx + 1));
                    $text_start_idx = null;
                    $bracket_ender = null;
                }
            }
        }
        $laws = [];
        foreach ($raw_texts as $raw_text) {
            $law = self::extractLawName($raw_text);
            if (isset($law) && ! in_array($law, $laws)) {
                $laws[] = $law;
            }
        }
        return $laws;
    }

    private static function parseReason($raw) {
    $start_idx = mb_strpos($raw, "（事由：");
    $end_idx = mb_strrpos($raw, "）");
    $content = mb_substr($raw, $start_idx + 4, $end_idx - ($start_idx + 4));
    $content = preg_replace('/【.*?】/', '', $content);
    $content = trim($content);
    return $content;
    }

    private static function parseSubjects($content, $first_order_indexes)
    {
        $subjects = [];
        $last_index = 0;
        foreach ($first_order_indexes as $order => $idx) {
            if ($order == 9) {
                //代表有可能該會會議要處理的事項超過十個
                $subjects[] = trim(mb_substr($content, $last_index + 2));
            }
            $current_index = mb_strpos($content, $first_order_indexes[$order + 1]);

            // current_index 應該要是最上層索引編號的位置
            // 但有時會遇到「第十六條之『二、』」的「二、」被認為是索引的誤判
            // 所以特別用下列的 code 偵測誤判並跳過
            $previous_char = mb_substr($content, $current_index - 1, 1);
            while ($current_index !== false && ! in_array($previous_char, ["\n", ' '])) {
                $current_index = mb_strpos($content, $first_order_indexes[$order + 1], $current_index + 2);
                $previous_char = mb_substr($content, $current_index - 1, 1);
            }

            if (! $current_index) {
                $subjects[] = trim(mb_substr($content, $last_index + 2));
                break;
            }
            $subjects[] = trim(mb_substr($content, $last_index + 2, $current_index - ($last_index + 2)));
            $last_index = $current_index;
        }
        return $subjects;
    }

    private static function getBillSubject($subject)
    {
        $keyword = '擬具';
        if (mb_strpos($subject, $keyword)) {
            $lines = explode("\n", $subject);
            $bill_cnt = 0;
            $law_raw = '';
            foreach ($lines as $line) {
                if (mb_strpos($line, $keyword)) {
                    $bill_cnt++;
                    $start_idx = mb_strpos($line, '「');
                    $end_idx = mb_strpos($line, '」');
                    $current_law_raw = mb_substr($line, $start_idx + 1, $end_idx - ($start_idx + 1));
                    //以提案中法條名稱字最少的那一個為準
                    if (mb_strlen($law_raw) == 0 || mb_strlen($law_raw) > mb_strlen($current_law_raw)) {
                        $law_raw = $current_law_raw;
                    }
                }
            }
            //擷取提案法條名稱中母法名稱
            $law = self::extractLawName($law_raw) ?? $law_raw;

            //辨認 commit 是全新、修正或增訂
            $isUpdate = mb_strpos($law_raw, '修正');
            $isAppend = mb_strpos($law_raw, '增訂');
            $law_type = '新法';
            if ($isUpdate) {
                $law_type = '修正';
            } else if ($isAppend) {
                $law_type = '增訂';
            }

            return ['bill', $law, $law_type, $bill_cnt];
        }
        return false;
    }

    private static function getI12nSubject($subject)
    {
        $keyword = '質詢';
        if (mb_strpos($subject, $keyword)) {
            return ['i12n', $subject];
        }
        return false;
    }

    private static function extractLawName($raw_text)
    {
        $law_end_idx1 = mb_strrpos($raw_text, '法');
        $law_end_idx2 = mb_strrpos($raw_text, '條例');
        $exception_end_idx1 = mb_strrpos($raw_text, '作法');
        $law_name = null;
        if ($law_end_idx1 && $law_end_idx1 != $exception_end_idx1 + 1) {
            $law_name = mb_substr($raw_text, 0, $law_end_idx1 + 1);
        } else if ($law_end_idx2) {
            $law_name = mb_substr($raw_text, 0, $law_end_idx2 + 2);
        }
        return $law_name;
    }
}
