<?php
/**
 * config/secrets.php
 * Sensitive API keys — never commit this file to version control
 * Add secrets.php to your .gitignore
 */

// Anthropic Claude API (used by lecturer/ajax/ai_generate.php)
define('ANTHROPIC_API_KEY', 'your_anthropic_api_key_here');
// Backward compatibility alias for older files.
define('CLAUDE_API_KEY', ANTHROPIC_API_KEY);

// SerpApi — Google AI Mode Search (used by lecturer/ajax/serpapi_search.php)
define('SERPAPI_KEY', 'https://serpapi.com/search?engine=google');


