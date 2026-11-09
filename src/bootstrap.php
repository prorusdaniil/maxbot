<?php
declare(strict_types=1);

define('BOT_ROOT', dirname(__DIR__));

require_once __DIR__ . '/Env.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Prompts.php';
require_once __DIR__ . '/Llm/LlmClient.php';
require_once __DIR__ . '/Llm/DeepSeekClient.php';
require_once __DIR__ . '/Llm/Llm.php';
require_once __DIR__ . '/Books.php';
require_once __DIR__ . '/Quiz.php';
require_once __DIR__ . '/MaxApi.php';
require_once __DIR__ . '/Sessions.php';
require_once __DIR__ . '/Bot.php';

loadEnvFile(BOT_ROOT . '/.env');
mb_internal_encoding('UTF-8');
