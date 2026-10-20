<?php
// Salin file ini menjadi config/api.php dan isi dengan API key asli.
// Jangan commit config/api.php ke repository.

define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '');
define('GROQ_MODEL', 'GANTI_DENGAN_MODEL_GROQ');
define('GROQ_ENDPOINT', 'https://api.groq.com/openai/v1/chat/completions');
define('LLM_TIMEOUT', 10);
define('LLM_CONNECT_TIMEOUT', 3);
