<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LaraClaw Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the LaraClaw multi-agent
    | personal assistant system.
    |
    */

    // If set to anything other than 'file' it will use the default laravel database connection
    'server_api_key' => env('LARACLAW_SERVER_API_KEY'),
    'server_host' => env('LARACLAW_SERVER_HOST', 'localhost'),
    'server_port' => env('LARACLAW_SERVER_PORT', 19123),

    // REST API key for securing HTTP endpoints (ChatController)
    'rest_api_key' => env('LARACLAW_REST_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Workspace Configuration
    |--------------------------------------------------------------------------
    */
    'workspace' => [
        'path' => env('LARACLAW_WORKSPACE_PATH', storage_path('app')),
        'name' => env('LARACLAW_WORKSPACE_NAME', 'laraclaw'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | LaraClaw supports multiple queue strategies for processing messages:
    |
    | - single: All messages go to one queue. Simple but no ordering guarantee.
    | - per_agent: Each agent gets its own queue. Guarantees ordering with 1 worker.
    | - priority: Agents grouped into priority tiers. Each agent still gets own queue.
    |
    | IMPORTANT: Ordering is only guaranteed when each queue is serviced by
    | exactly ONE worker. Multiple workers on the same queue can process
    | messages out of order.
    |
    */
    'queue' => [
        // Legacy file-based queue paths (kept for compatibility)
        'incoming' => env('LARACLAW_QUEUE_INCOMING', storage_path('app/laraclaw/queue/incoming')),
        'outgoing' => env('LARACLAW_QUEUE_OUTGOING', storage_path('app/laraclaw/queue/outgoing')),
        'processing' => env('LARACLAW_QUEUE_PROCESSING', storage_path('app/laraclaw/queue/processing')),

        /*
        |----------------------------------------------------------------------
        | Queue Strategy
        |----------------------------------------------------------------------
        |
        | Options: 'single', 'per_agent', 'priority'
        |
        | single:     All messages → 'default' queue
        |             + Simple setup
        |             + Easy horizontal scaling
        |             - No per-agent ordering guarantee
        |
        | per_agent:  Each agent → 'agent-{id}' queue
        |             + Guaranteed in-order processing per agent
        |             + Agent isolation (slow agent doesn't block others)
        |             - More complex setup
        |             - Requires 1 worker per agent
        |
        | priority:   Agents grouped into priority tiers
        |             + Ordering guaranteed (each agent still gets own queue)
        |             + Priority-based resource allocation
        |             - Moderate complexity
        |
        */
        'strategy' => env('LARACLAW_QUEUE_STRATEGY', 'single'),

        /*
        |----------------------------------------------------------------------
        | Single Queue Settings
        |----------------------------------------------------------------------
        */
        'single_queue_name' => env('LARACLAW_SINGLE_QUEUE_NAME', 'default'),
        'single_max_processes' => env('LARACLAW_SINGLE_MAX_PROCESSES', 5),

        /*
        |----------------------------------------------------------------------
        | Per-Agent Queue Settings
        |----------------------------------------------------------------------
        */
        'agent_queue_prefix' => env('LARACLAW_AGENT_QUEUE_PREFIX', 'agent-'),

        /*
        |----------------------------------------------------------------------
        | Priority Queue Settings
        |----------------------------------------------------------------------
        |
        | Define priority tiers with agent assignments and resource limits.
        | Each agent still gets its own queue for ordering guarantees.
        | Use '*' as a wildcard to match all agents not explicitly listed.
        |
        | Example:
        | 'priority_tiers' => [
        |     'high' => [
        |         'agents' => ['coder', 'assistant'],
        |         'max_processes' => 5,
        |         'processes_per_agent' => 1,
        |     ],
        |     'default' => [
        |         'agents' => ['*'],
        |         'max_processes' => 3,
        |         'processes_per_agent' => 1,
        |     ],
        | ],
        |
        */
        'priority_tiers' => [
            'high' => [
                'agents' => env('LARACLAW_PRIORITY_HIGH_AGENTS', '')
                    ? explode(',', env('LARACLAW_PRIORITY_HIGH_AGENS', ''))
                    : [],
                'max_processes' => env('LARACLAW_PRIORITY_HIGH_MAX_PROCESSES', 5),
                'processes_per_agent' => env('LARACLAW_PRIORITY_HIGH_PROCESSES_PER_AGENT', 1),
            ],
            'default' => [
                'agents' => ['*'],
                'max_processes' => env('LARACLAW_PRIORITY_DEFAULT_MAX_PROCESSES', 3),
                'processes_per_agent' => env('LARACLAW_PRIORITY_DEFAULT_PROCESSES_PER_AGENT', 1),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Job Settings
        |----------------------------------------------------------------------
        */
        'job_timeout' => env('LARACLAW_QUEUE_JOB_TIMEOUT', 300), // 5 minutes for AI responses
        'job_tries' => env('LARACLAW_QUEUE_JOB_TRIES', 3),
        'job_retry_after' => env('LARACLAW_QUEUE_JOB_RETRY_AFTER', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Files Configuration
    |--------------------------------------------------------------------------
    */
    'files' => [
        'dir' => env('LARACLAW_FILES_DIR') ?: storage_path('app/laraclaw/files'),
        'events_dir' => env('LARACLAW_EVENTS_DIR') ?: storage_path('app/laraclaw/events'),
        'chats_dir' => env('LARACLAW_CHATS_DIR') ?: storage_path('app/laraclaw/chats'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat History Configuration
    |--------------------------------------------------------------------------
    |
    | Configure chat history storage. By default, conversations are stored
    | in the database for searchability. Optionally export to markdown files.
    |
    */
    'chat_history' => [
        // Export conversations to markdown files (for backup/compatibility)
        'export_to_files' => env('LARACLAW_CHAT_EXPORT_FILES', false),

        // Auto-cleanup old conversations (days, 0 = disabled)
        'cleanup_days' => env('LARACLAW_CHAT_CLEANUP_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Configuration
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        'heartbeat_interval' => env('LARACLAW_HEARTBEAT_INTERVAL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation Limits
    |--------------------------------------------------------------------------
    */
    'conversation' => [
        'max_messages' => env('LARACLAW_MAX_CONVERSATION_MESSAGES', 50),
        'long_response_threshold' => env('LARACLAW_LONG_RESPONSE_THRESHOLD', 4000),

        /*
        |----------------------------------------------------------------------
        | Cache Configuration for Conversation State
        |----------------------------------------------------------------------
        |
        | Conversation state is stored in Laravel Cache (Redis/database/file)
        | for persistence across queue workers and process restarts.
        |
        */
        'cache_prefix' => env('LARACLAW_CONVERSATION_CACHE_PREFIX', 'laraclaw:conv:'),
        'cache_ttl' => env('LARACLAW_CONVERSATION_CACHE_TTL', 3600), // 1 hour default
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the lossless memory system for hierarchical summarization.
    |
    | The lossless memory system preserves all original messages while creating
    | layered summaries for efficient context management. This enables:
    | - Complete conversation history preservation
    | - Hierarchical summarization (leaf summaries for messages, condensed for summaries)
    | - Token budget management with automatic compaction
    | - Fresh tail protection for recent messages
    |
    */
    'memory' => [
        'lossless_enabled' => env('LARACLAW_MEMORY_LOSSLESS', true),
        'lossless_token_budget' => env('LARACLAW_MEMORY_TOKEN_BUDGET', 100000),
        'lossless_context_threshold' => env('LARACLAW_MEMORY_CONTEXT_THRESHOLD', 0.75),
        'lossless_fresh_tail' => env('LARACLAW_MEMORY_FRESH_TAIL', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Registry
    |--------------------------------------------------------------------------
    |
    | Complete provider configuration including models, display names, and
    | capabilities. Used by setup wizard, agent management, and runtime.
    |
    | Each provider has:
    | - display: Human-readable name for UI
    | - recommended: Whether to highlight as recommended choice
    | - models: Map of model_id => display_name
    | - default_model: The default model for this provider
    | - api_key: ENV variable name for API key (null if not required)
    | - supports_text: Can generate text responses
    | - supports_embeddings: Can generate embeddings
    | - note: Optional note about the provider
    |
    */
    'providers' => [
        'anthropic' => [
            'display' => 'Anthropic (Claude)',
            'recommended' => true,
            'models' => [
                'claude-sonnet-4-20250514' => 'Claude Sonnet 4 (fast, recommended)',
                'claude-opus-4-20250514' => 'Claude Opus 4 (smartest)',
                'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet',
                'claude-3-5-haiku-20241022' => 'Claude 3.5 Haiku (fastest)',
                'claude-3-opus-20240229' => 'Claude 3 Opus',
            ],
            'default_model' => 'claude-sonnet-4-20250514',
            'api_key' => 'ANTHROPIC_API_KEY',
            'supports_text' => true,
            'supports_embeddings' => false,
        ],
        'deepseek' => [
            'display' => 'DeepSeek',
            'recommended' => false,
            'models' => [
                'deepseek-chat' => 'DeepSeek Chat (recommended)',
                'deepseek-coder' => 'DeepSeek Coder',
                'deepseek-reasoner' => 'DeepSeek Reasoner',
            ],
            'default_model' => 'deepseek-chat',
            'api_key' => 'DEEPSEEK_API_KEY',
            'supports_text' => true,
            'supports_embeddings' => false,
        ],
        'elevenlabs' => [
            'display' => 'ElevenLabs (Text-to-Speech)',
            'recommended' => false,
            'models' => [
                'eleven_multilingual_v2' => 'Multilingual v2 (recommended)',
                'eleven_monolingual_v1' => 'Monolingual v1 (English)',
                'eleven_turbo_v2' => 'Turbo v2 (fast)',
                'eleven_turbo_v2_5' => 'Turbo v2.5 (fastest)',
            ],
            'default_model' => 'eleven_multilingual_v2',
            'api_key' => 'ELEVENLABS_API_KEY',
            'supports_text' => false,
            'supports_embeddings' => false,
            'note' => 'Text-to-Speech provider - use for voice output',
        ],
        'gemini' => [
            'display' => 'Google Gemini',
            'recommended' => false,
            'models' => [
                'gemini-1.5-pro' => 'Gemini 1.5 Pro (recommended)',
                'gemini-1.5-flash' => 'Gemini 1.5 Flash (fast)',
                'gemini-1.5-flash-8b' => 'Gemini 1.5 Flash 8B',
                'gemini-2.0-flash-exp' => 'Gemini 2.0 Flash (experimental)',
            ],
            'default_model' => 'gemini-1.5-pro',
            'api_key' => 'GEMINI_API_KEY',
            'supports_text' => true,
            'supports_embeddings' => true,
        ],
        'groq' => [
            'display' => 'Groq (Fast Inference)',
            'recommended' => false,
            'models' => [
                'llama-3.3-70b-versatile' => 'Llama 3.3 70B (recommended)',
                'llama-3.1-70b-versatile' => 'Llama 3.1 70B',
                'llama-3.1-8b-instant' => 'Llama 3.1 8B (fast)',
                'mixtral-8x7b-32768' => 'Mixtral 8x7B',
                'gemma2-9b-it' => 'Gemma 2 9B',
            ],
            'default_model' => 'llama-3.3-70b-versatile',
            'api_key' => 'GROQ_API_KEY',
            'supports_text' => true,
            'supports_embeddings' => false,
        ],
        'mistral' => [
            'display' => 'Mistral AI',
            'recommended' => false,
            'models' => [
                'mistral-large-latest' => 'Mistral Large (recommended)',
                'mistral-medium-latest' => 'Mistral Medium',
                'mistral-small-latest' => 'Mistral Small',
                'codestral-latest' => 'Codestral (code)',
                'open-mistral-nemo' => 'Mistral Nemo',
                'open-codestral-mamba' => 'Codestral Mamba',
            ],
            'default_model' => 'mistral-large-latest',
            'api_key' => 'MISTRAL_API_KEY',
            'supports_text' => true,
            'supports_embeddings' => true,
        ],
        'ollama' => [
            'display' => 'Ollama (Local)',
            'recommended' => false,
            'models' => [
                'llama3.2' => 'Llama 3.2 (recommended)',
                'llama3.1' => 'Llama 3.1',
                'llama3' => 'Llama 3',
                'codellama' => 'Code Llama',
                'mistral' => 'Mistral',
                'mixtral' => 'Mixtral',
                'qwen2.5' => 'Qwen 2.5',
                'deepseek-coder' => 'DeepSeek Coder',
                'phi3' => 'Phi-3',
            ],
            'default_model' => 'llama3.2',
            'api_key' => null,
            'supports_text' => true,
            'supports_embeddings' => true,
        ],
        'openai' => [
            'display' => 'OpenAI (GPT)',
            'recommended' => false,
            'models' => [
                'gpt-4o' => 'GPT-4o (recommended)',
                'gpt-4o-mini' => 'GPT-4o Mini (fast)',
                'gpt-4-turbo' => 'GPT-4 Turbo',
                'gpt-4' => 'GPT-4',
                'gpt-3.5-turbo' => 'GPT-3.5 Turbo (cheapest)',
                'o1' => 'o1 (reasoning)',
                'o1-mini' => 'o1 Mini',
                'o1-preview' => 'o1 Preview',
            ],
            'default_model' => 'gpt-4o',
            'api_key' => 'OPENAI_API_KEY',
            'supports_text' => true,
            'supports_embeddings' => true,
        ],
        'openrouter' => [
            'display' => 'OpenRouter (Multi-model)',
            'recommended' => false,
            'models' => [
                'anthropic/claude-sonnet-4' => 'Claude Sonnet 4 (via OpenRouter)',
                'anthropic/claude-opus-4' => 'Claude Opus 4 (via OpenRouter)',
                'openai/gpt-4o' => 'GPT-4o (via OpenRouter)',
                'google/gemini-pro-1.5' => 'Gemini 1.5 Pro (via OpenRouter)',
                'meta-llama/llama-3.3-70b-instruct' => 'Llama 3.3 70B (via OpenRouter)',
                'deepseek/deepseek-chat' => 'DeepSeek Chat (via OpenRouter)',
                'mistralai/mistral-large' => 'Mistral Large (via OpenRouter)',
            ],
            'default_model' => 'anthropic/claude-sonnet-4',
            'api_key' => 'OPENROUTER_API_KEY',
            'supports_text' => true,
            'supports_embeddings' => false,
        ],
        'voyageai' => [
            'display' => 'Voyage AI (Embeddings)',
            'recommended' => false,
            'models' => [
                'voyage-3-large' => 'Voyage 3 Large (best quality)',
                'voyage-3' => 'Voyage 3 (recommended)',
                'voyage-3-lite' => 'Voyage 3 Lite (fast)',
                'voyage-code-3' => 'Voyage Code 3 (code)',
                'voyage-finance-2' => 'Voyage Finance 2',
                'voyage-law-2' => 'Voyage Law 2',
            ],
            'default_model' => 'voyage-3',
            'api_key' => 'VOYAGEAI_API_KEY',
            'supports_text' => false,
            'supports_embeddings' => true,
            'note' => 'Embeddings provider - use for semantic search',
        ],
        'xai' => [
            'display' => 'xAI (Grok)',
            'recommended' => false,
            'models' => [
                'grok-beta' => 'Grok Beta (recommended)',
                'grok-2-1212' => 'Grok 2',
                'grok-2-vision-1212' => 'Grok 2 Vision',
            ],
            'default_model' => 'grok-beta',
            'api_key' => 'XAI_API_KEY',
            'supports_text' => true,
            'supports_embeddings' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel Configuration
    |--------------------------------------------------------------------------
    |
    | Channel-specific configuration (tokens, session directories, etc.).
    | Enabled channels are stored in the database via SettingsService,
    | not in .env or config files.
    |
    */
    'channels' => [
        'telegram' => [
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        ],

        'discord' => [
            'bot_token' => env('DISCORD_BOT_TOKEN'),
        ],

        'whatsapp' => [
            'session_dir' => env('WHATSAPP_SESSION_DIR', storage_path('app/laraclaw/whatsapp-session')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Provider Configuration
    |--------------------------------------------------------------------------
    */
    'provider' => [
        'default' => env('LARACLAW_DEFAULT_PROVIDER', 'anthropic'),

        'anthropic' => [
            'model' => env('ANTHROPIC_MODEL', 'sonnet'),
            'api_key' => env('ANTHROPIC_API_KEY'),
        ],

        'openai' => [
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'api_key' => env('OPENAI_API_KEY'),
        ],

        'gemini' => [
            'model' => env('GEMINI_MODEL', 'gemini-1.5-pro'),
            'api_key' => env('GEMINI_API_KEY'),
        ],

        'groq' => [
            'model' => env('GROQ_MODEL', 'llama-3.3-70b'),
            'api_key' => env('GROQ_API_KEY'),
        ],

        'mistral' => [
            'model' => env('MISTRAL_MODEL', 'mistral-large-latest'),
            'api_key' => env('MISTRAL_API_KEY'),
        ],

        'xai' => [
            'model' => env('XAI_MODEL', 'grok-beta'),
            'api_key' => env('XAI_API_KEY'),
        ],

        'deepseek' => [
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'api_key' => env('DEEPSEEK_API_KEY'),
        ],

        'ollama' => [
            'model' => env('OLLAMA_MODEL', 'llama3'),
            'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Intent Classification Configuration
    |--------------------------------------------------------------------------
    |
    | Configure intent categories and ignore words for the intent classifier.
    | These settings allow customization without modifying code.
    |
    */
    'intent_classification' => [
        /*
        |----------------------------------------------------------------------
        | Intent Categories
        |----------------------------------------------------------------------
        |
        | Predefined intent categories for classification. Each category has:
        | - description: What this intent represents
        | - examples: Keywords/phrases that indicate this intent
        |
        | Domain-specific intents should be discovered dynamically from skills.
        |
        */
        'intent_categories' => [
            'question' => [
                'description' => 'User is asking a question seeking information',
                'examples' => ['what is', 'how do', 'why does', 'when did', 'where can', 'who is'],
            ],
            'command' => [
                'description' => 'User wants something done or executed',
                'examples' => ['fix', 'create', 'delete', 'update', 'send', 'schedule', 'run'],
            ],
            'conversation' => [
                'description' => 'Casual conversation or greeting',
                'examples' => ['hello', 'hi', 'thanks', 'goodbye', 'how are you'],
            ],
            'research' => [
                'description' => 'User needs research or analysis',
                'examples' => ['research', 'analyze', 'compare', 'investigate', 'find out'],
            ],
            'coding' => [
                'description' => 'Programming or code-related task',
                'examples' => ['code', 'function', 'bug', 'debug', 'refactor', 'implement', 'script'],
            ],
            'creative' => [
                'description' => 'Creative writing or content generation',
                'examples' => ['write', 'compose', 'design', 'create', 'generate', 'draft'],
            ],
            'scheduling' => [
                'description' => 'Calendar or time management',
                'examples' => ['schedule', 'meeting', 'appointment', 'calendar', 'reminder'],
            ],
            'unknown' => [
                'description' => 'Unable to determine intent',
                'examples' => [],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Ignore Words
        |----------------------------------------------------------------------
        |
        | Words to filter out from keyword extraction. These are common words
        | that don't carry significant meaning for intent classification.
        |
        */
        'ignore_words' => [
            'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
            'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been',
            'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
            'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'this',
            'that', 'these', 'those', 'i', 'you', 'he', 'she', 'it', 'we', 'they',
            'what', 'which', 'who', 'when', 'where', 'why', 'how', 'all', 'each',
            'every', 'both', 'few', 'more', 'most', 'other', 'some', 'such', 'no',
            'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very',
            'please', 'want', 'need', 'like', 'just', 'me', 'my', 'your',
        ],

        /*
        |----------------------------------------------------------------------
        | Cache Settings
        |----------------------------------------------------------------------
        */
        'cache_ttl' => env('LARACLAW_INTENT_CACHE_TTL', 3600),
        'cache_min_confidence' => env('LARACLAW_INTENT_CACHE_MIN_CONFIDENCE', 0.7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Skill Pre-Classification Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the skill pre-classification system that populates the
    | intent cache with sample mappings for faster skill matching.
    |
    */
    'skill_preclassification' => [
        /*
        |----------------------------------------------------------------------
        | Enable Pre-Classification
        |----------------------------------------------------------------------
        |
        | Master switch to enable or disable skill pre-classification during
        | setup. When disabled, the setup wizard will skip this step.
        |
        */
        'enabled' => env('LARACLAW_SKILL_PRECLASSIFICATION', true),

        /*
        |----------------------------------------------------------------------
        | Intents Per Skill
        |----------------------------------------------------------------------
        |
        | Number of sample intents to generate for each skill during
        | pre-classification. Higher values mean better coverage but
        | more LLM tokens consumed.
        |
        */
        'intents_per_skill' => env('LARACLAW_INTENTS_PER_SKILL', 5),

        /*
        |----------------------------------------------------------------------
        | Skip on Reset
        |----------------------------------------------------------------------
        |
        | Whether to skip pre-classification when running setup with --reset.
        | Useful for quick resets without re-classifying skills.
        |
        */
        'skip_on_reset' => env('LARACLAW_SKIP_PRECLASSIFICATION_ON_RESET', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Skills Configuration
    |--------------------------------------------------------------------------
    |
    | Configure skill matching, execution, and auto-discovery behavior.
    |
    */
    'skills' => [
        /*
        |----------------------------------------------------------------------
        | Direct Execution Threshold
        |----------------------------------------------------------------------
        |
        | Minimum confidence level required for direct skill execution without
        | LLM invocation. When a skill match exceeds this threshold, the skill
        | script is executed directly, saving tokens and reducing latency.
        |
        | Set to 1.0 to disable direct execution (always use LLM).
        | Set to 0.0 to always attempt direct execution.
        | Recommended: 0.85 (high confidence required).
        |
        */
        'direct_execution_threshold' => env('LARACLAW_SKILL_DIRECT_THRESHOLD', 0.85),

        /*
        |----------------------------------------------------------------------
        | Skills Directory
        |----------------------------------------------------------------------
        |
        | Directory where skill definitions are stored. Each skill has its own
        | subdirectory containing SKILL.md, scripts/, and references/.
        |
        */
        'directory' => env('LARACLAW_SKILLS_DIR', base_path('agents/skills')),

        /*
        |----------------------------------------------------------------------
        | Gap Detection Threshold
        |----------------------------------------------------------------------
        |
        | Confidence threshold below which skill auto-discovery is triggered.
        | When intent classification returns a skill match with confidence
        | below this threshold, the system will search for skills via
        | `npx skills find` to potentially install a better match.
        |
        | Set to 0.0 to disable gap detection.
        | Recommended: 0.5 (trigger discovery when confidence is low).
        |
        */
        'gap_detection_threshold' => env('LARACLAW_SKILL_GAP_THRESHOLD', 0.5),

        /*
        |----------------------------------------------------------------------
        | Auto-Install
        |----------------------------------------------------------------------
        |
        | Enable automatic installation of skills when a gap is detected.
        | When enabled, the system will automatically install matching skills
        | from the registry using `npx skills add`.
        |
        | When disabled, the system will prompt the user to choose whether
        | to install a discovered skill.
        |
        */
        'auto_install' => env('LARACLAW_SKILL_AUTO_INSTALL', false),

        /*
        |----------------------------------------------------------------------
        | Auto-Install Mode
        |----------------------------------------------------------------------
        |
        | When auto_install is enabled and multiple skills are found, this
        | determines how the system handles the choice:
        |
        | - 'first': Automatically install the first (most popular) skill.
        |            Skills are sorted by number of installs from the registry.
        |
        | - 'prompt': Show the user a list of matching skills and let them
        |             choose which one to install.
        |
        | Note: When only a single skill is found, it is always auto-installed
        | (if auto_install is enabled), regardless of this setting.
        |
        */
        'auto_install_mode' => env('LARACLAW_SKILL_AUTO_INSTALL_MODE', 'prompt'),

        /*
        |----------------------------------------------------------------------
        | Max Discovery Results
        |----------------------------------------------------------------------
        |
        | Maximum number of skills to show when prompting the user to choose.
        | Only applies when auto_install_mode is 'prompt'.
        |
        */
        'max_discovery_results' => env('LARACLAW_SKILL_MAX_DISCOVERY_RESULTS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Script Execution Configuration
    |--------------------------------------------------------------------------
    |
    | Configure script execution for skill scripts. This allows the AI to
    | execute shell scripts, Python scripts, and TypeScript scripts from
    | skill directories with proper security sandboxing.
    |
    */
    'script_execution' => [
        /*
        |----------------------------------------------------------------------
        | Enable/Disable Script Execution
        |----------------------------------------------------------------------
        |
        | Master switch to enable or disable script execution globally.
        | When disabled, all script execution requests will return an error.
        |
        */
        'enabled' => env('LARACLAW_SCRIPT_EXECUTION', true),

        /*
        |----------------------------------------------------------------------
        | Execution Timeout
        |----------------------------------------------------------------------
        |
        | Maximum time in seconds that a script is allowed to run before
        | being terminated. This prevents runaway scripts from blocking
        | the system indefinitely.
        |
        */
        'timeout' => env('LARACLAW_SCRIPT_TIMEOUT', 30),

        /*
        |----------------------------------------------------------------------
        | Maximum Output Size
        |----------------------------------------------------------------------
        |
        | Maximum size in bytes for script output. Output exceeding this
        | limit will be truncated. This prevents memory issues from scripts
        | that generate excessive output.
        |
        */
        'max_output_size' => env('LARACLAW_MAX_SCRIPT_OUTPUT', 10000),

        /*
        |----------------------------------------------------------------------
        | Allowed Script Extensions
        |----------------------------------------------------------------------
        |
        | Whitelist of script file extensions that are allowed to execute.
        | Only scripts with these extensions can be run by the AI.
        |
        | - sh: Shell scripts (executed with bash)
        | - py: Python scripts (executed with python3)
        | - ts: TypeScript scripts (executed with npx ts-node)
        | - js: JavaScript scripts (executed with node)
        |
        */
        'allowed_extensions' => [
            env('LARACLAW_ALLOWED_EXTENSIONS', 'sh,py,ts,js')
                ? explode(',', env('LARACLAW_ALLOWED_EXTENSIONS', 'sh,py,ts,js'))
                : ['sh', 'py', 'ts', 'js'],
        ][0],

        /*
        |----------------------------------------------------------------------
        | Blocked Commands
        |----------------------------------------------------------------------
        |
        | Blacklist of command patterns that are blocked from execution.
        | These patterns are checked against the full command string
        | before execution. If any pattern matches, execution is denied.
        |
        | WARNING: This is a security measure but should not be the only
        | line of defense. Always run scripts in a sandboxed environment.
        |
        */
        'blocked_commands' => [
            // Dangerous filesystem operations
            'rm -rf /',
            'rm -rf /*',
            'chmod 777',
            'chmod -R 777',
            'mkfs',

            // Disk operations
            'dd if=',
            'dd if=/dev/',
            '> /dev/sd',
            '> /dev/hd',

            // Privilege escalation
            'sudo ',
            'su ',
            'doas ',

            // Remote code execution
            'curl | bash',
            'wget | bash',
            'curl | sh',
            'wget | sh',

            // Fork bombs and resource exhaustion
            ':(){ :|:& };:',
            'fork bomb',
        ],
    ],
];
