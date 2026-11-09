<?php
declare(strict_types=1);

/** Системные промпты лежат в prompts/<имя>.txt, чтобы менять их без правки кода. */
final class Prompts
{
    public static function get(string $name): string
    {
        $path = BOT_ROOT . '/prompts/' . $name . '.txt';
        $text = preg_match('/^[a-z_]+$/', $name) ? @file_get_contents($path) : false;
        if ($text === false || trim($text) === '') {
            throw new LlmConfigurationError("Не найден промпт prompts/$name.txt");
        }
        return trim($text);
    }
}
