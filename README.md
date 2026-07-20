# Archived

This project is archived, no longer maintained and should only be used a general reference of how an OpenClaw type system  can be built on Laravel. 

# LaraClaw

A Laravel-based multi-agent AI orchestration framework that routes user messages across configurable LLM-powered agents and teams via multiple channels (CLI, WebSocket, Discord), with intent classification, skill discovery and execution, episodic memory, and conversation session management.

---

## Table of Contents

- [Requirements](#requirements)
- [Why PHP/Laravel](#why-phplaravel)
- [Warning](#warning)
- [Storage](#storage)
- [Installation](#installation)
- [Setup Wizard](#setup-wizard)
- [Workspace Directory Structure](#workspace-directory-structure)
- [The .agents Symlink](#the-agents-symlink)
- [Starting LaraClaw](#starting-laraclaw)
- [Skills](#skills)
- [Using LaraClaw](#using-laraclaw)
- [Configuration Reference](#configuration-reference)
- [Security](#security)

---

## Requirements

- PHP 8.4+
- Composer
- Node.js & npm
- SQLite (default) or MySQL/PostgreSQL
- An LLM provider API key (Anthropic, OpenAI, Google, Ollama, etc.)

---

## Why PHP/Laravel

Most Claw-type systems are written in NodeJS, Go, or Rust. Especially for implementations in the latter two languages, they aim to maximize raw performance and minimze RAM usage. While those are admirable goals, we believe that the raw execution speed of the implementation language is largely irrelevant: most of the time is spent sending prompts to LLMs or calling external APIs, which are orders of magnitude slower than any local PHP request.

Laravel, on the other hand, provides a battle-tested, developer-friendly stack that supports multiple database backends, robust queue processing, a solid command line job framework, dependency injection and a host of other features that make developers’ lives easier.

Most importantly, a PHP-based implementation makes this type of technology accessible to millions of developers who use PHP as their primary language, allowing them to learn, experiment, and contribute.

---

## Warning

Most of this code has been vibe-coded using Claude and GPT5, with some manual corrections and fixes applied during the course of the implementation and testing. It has been tested lightly and seems to work, but there is a chance that you will stumble across some issues as all of this is brand-new. Accordingly, you should treat this code (for now) as *beta* that may still have issues. 

In a similar vain, I'm not happy with the code quality (see phpstan warnings) but I hope to address this issue in the near future. 

---

## Storage

LaraClaw uses standard Laravel Eloquent models for data storage. The default in .env.example is set to Sqlite, but if you prefer, you can switch to Mysql or PostGres; this *should* work but no guarantees are given. 

---

## Installation

Clone the repository and install dependencies:

```bash
git clone https://github.com/robertgdev/LaraClaw.git
cd LaraClaw

composer install
npm install

cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

> **Tip:** You can also run `composer setup` which executes all the steps above in sequence.

---

## Setup Wizard

Run the interactive setup wizard to configure LaraClaw:

```bash
php artisan laraclaw:setup
```

The wizard walks you through the following steps:

1. **Channel Selection** — Choose which (if any) channels to enable (Discord) and set polling intervals for each.
2. **LLM Provider Selection** — Select your LLM provider (Anthropic, OpenAI, DeepSeek, Google, Ollama, etc.) and enter your API key. You **must** select at least one provider. The wizard will offer to test the connection.
3. **Heartbeat Interval** — Configure the agent heartbeat interval for monitoring.
4. **Workspace Configuration** — Set the workspace name and path where agent directories, skills, chat history, and files are stored.
5. **Default Agent** — Create your first agent with a name, ID, provider, and model.
6. **Additional Agents** — Optionally create more agents with different roles, providers, or models. Each agent gets a unique `@agent_id` that users can mention to route messages.
7. **Review & Confirm** — Review the full configuration summary, approve the `.env` changes, and optionally run skill pre-classification (consumes LLM tokens but improves skill matching speed).

To reset all configuration and start fresh:

```bash
php artisan laraclaw:setup --reset
```

---

## Workspace Directory Structure

After setup, LaraClaw creates a workspace at `storage/app/laraclaw-workspace/` (or your configured path):

```
storage/app/laraclaw-workspace/
├── <agent_id>/                  # Per-agent working directories
│   ├── AGENTS.md                # Core agent instructions (injected into system prompt)
│   ├── .laraclaw/
│   │   └── SOUL.md              # Agent identity, personality, worldview
│   ├── .claude/
│   │   ├── CLAUDE.md            # Copy of AGENTS.md for Claude CLI compatibility
│   │   └── skills -> ../agents/skills   # Symlink to shared skills
│   ├── .agents/
│   │   └── skills -> ../../agents/skills  # Symlink to shared skills
│   └── heartbeat.md             # Agent heartbeat file
├── agents/
│   └── skills/                  # Shared skill definitions
│       └── <skill_name>/
│           ├── SKILL.md          # Skill manifest and instructions
│           ├── scripts/          # Executable scripts (sh, py, ts, js)
│           ├── references/       # Reference files for the LLM
│           └── assets/           # Static assets
├── chats/                       # Exported chat history (markdown files)
├── files/                       # User-uploaded files
└── templates/                   # Template files for new agents
```

Each agent gets its own working directory with `AGENTS.md` (core instructions) and `SOUL.md` (personality/identity). These files are combined into the system prompt when the agent is invoked.

---

## The .agents Symlink

During setup, LaraClaw creates a `.agents` symlink in your project root:

```
.agents -> storage/app/laraclaw-workspace/agents/
```

This makes the shared `skills/` directory accessible from the project root, which:

- Allows `npx skills` CLI commands to discover and manage skills
- Provides a consistent path for skill scripts regardless of workspace configuration
- Keeps the actual skill files in `storage/` where they belong while maintaining compatibility with the skills.sh ecosystem

The symlink is created automatically during setup. If it already exists, setup will skip this step.

---

## Starting LaraClaw

LaraClaw has several processes that work together. Here's what each one does and when to start it:

### 1. Laravel HTTP Server

```bash
php artisan serve
```

Starts the Laravel development server on `http://localhost:8000`. This serves the web-based chat client and the REST API. Required for the web UI.  If you have installed LaraClaw under a local (or remote) web server, you can skip this step. 

### 2. WebSocket Server

```bash
php artisan laraclaw:server start
```

Starts the LaraClaw WebSocket server (default port: `19123`). The web chat client connects to this server for real-time messaging. It handles:

- WebSocket handshake and authentication (using the `LARACLAW_SERVER_API_KEY` from `.env`)
- Message routing to agents via the `CommandProcessingService`
- Slash commands (`/agents`, `/teams`, `/status`, `/history`, `/help`, etc.)

Other server actions:

```bash
php artisan laraclaw:server status   # Check if the server is running
php artisan laraclaw:server stop     # Stop the server
```

### 3. Discord Bot (optional)

```bash
php artisan laraclaw:discord
```

If you wish to communicate via Discord, start the Discord bot client using this command. Requires `DISCORD_BOT_TOKEN` in your `.env` and the Discord channel to be enabled during setup. The bot connects to Discord via the gateway and polls for messages. 

For information on how to obtain a Discord API Key and other related setup steps, see the [docs/DiscordSetup.md](Discord Setup) document.

### 4. Queue Worker

```bash
php artisan queue:work
```

Processes background jobs such as async message handling via the `ProcessMessageJob`. The queue worker is necessary for:

- Asynchronous message processing (non-blocking responses)
- Team conversation chains (agents passing messages to teammates)
- Scheduled tasks

**Alternative:** If you have [Laravel Horizon](https://laravel.com/docs/horizon) installed, you can use it instead for a 
dashboard-based queue manager:

```bash
php artisan horizon
```

### 5. Scheduler

Some commands should be run periodically. Per default this is the memory consolidation and the backup job. In order 
for these to be processed, you need to start the scheduler. Documentation for this can be found 
at the [Laravel Scheduler](https://laravel.com/docs/12.x/scheduling#running-the-scheduler) page. 

Locally, you can do this via the command

```
php artisan schedule:work
```

which will run the scheduler continously. 

On a server, the best way to do this is via an entry in crontab like this: 

```
* * * * * php /var/www/myapp/artisan schedule:run >> /dev/null 2>&1
```


### Quick Start (all at once)

For development, you can start all processes with the built-in dev script:

```bash
composer dev
```

This uses `concurrently` to run the HTTP server, queue worker, logs, and Vite dev server in parallel.

---

## Skills

Skills are modular capabilities that agents can invoke. Each skill lives in its own directory with a `SKILL.md` manifest, optional executable scripts, references, and assets.

### Installing Skills from the Registry

Use the interactive skill installer to search and install skills from the [skills.sh](https://skills.sh) registry:

```bash
php artisan laraclaw:skill:install
```

This opens an interactive shell where you can:

1. Search for skills by name or category
2. Browse results with install counts
3. Select and install skills with a single keystroke

You can also pass a search term directly:

```bash
php artisan laraclaw:skill:install "web browsing"
```

### Installing Skills Manually

You can install skills manually from other sites (ex: clawhub.ai) or by placing them in the skills directory:

1. Navigate to the skills directory:
   ```bash
   cd .agents/skills/
   ```

2. Create a new skill directory with a `SKILL.md` file:
   ```bash
   mkdir my-skill
   cat > my-skill/SKILL.md << 'EOF'
   ---
   name: my-skill
   description: "A brief description of what the skill does"
   ---

   # My Skill

   Detailed instructions for the LLM on how and when to use this skill.
   EOF
   ```

3. Optionally add executable scripts:
   ```bash
   mkdir my-skill/scripts
   echo '#!/bin/bash\necho "Hello from my skill"' > my-skill/scripts/run.sh
   chmod +x my-skill/scripts/run.sh
   ```

4. Refresh the skill index:
   ```bash
   php artisan laraclaw:skills
   ```

The laraclaw:skill:install is a wrapper; you can also use `npx skills add owner/repo@skill` directly if you have the skills CLI installed.

### Skill Classification

After installing new skills, you can classify them to populate the intent cache. This reduces LLM calls for skill matching by storing 
the relevant information in a local table:

```bash
php artisan laraclaw:skills --classify
```

---

## Using LaraClaw

### Web Client

After starting both the HTTP server and WebSocket server, open your browser:

```
http://localhost:8000
```

The web client provides a chat interface that connects to the WebSocket server. You can:

- Send messages to the default agent
- Mention specific agents with `@agent_id your message`
- Use slash commands: `/agents`, `/teams`, `/status`, `/history`, `/help`
- Manage conversation sessions

### Single Command (CLI)

Send a one-off command to an agent from the terminal:

```bash
php artisan laraclaw:command "What is the weather in Berlin?"
```

Options:

```bash
php artisan laraclaw:command "your message" --agent=coder      # Route to specific agent
php artisan laraclaw:command "your message" --team=dev-team     # Route to team leader
php artisan laraclaw:command "your message" --reset             # Reset conversation context
php artisan laraclaw:command "your message" --json              # Output as JSON
```

The command uses intelligent routing: if you don't specify an agent, LaraClaw classifies your intent and routes to the best-matching agent based on capabilities and skills.

### Interactive Chat Shell

Start a persistent shell session for back-and-forth conversation:

```bash
php artisan laraclaw:chat
```

Options:

```bash
php artisan laraclaw:chat --agent=coder        # Default to specific agent
php artisan laraclaw:chat --team=dev-team       # Default to specific team
php artisan laraclaw:chat --reset               # Reset on start
```

Inside the shell you can:

- Type messages directly to chat with the default agent
- Use `@agent_id message` to route to a specific agent
- Use `/` commands: `/agents`, `/teams`, `/status`, `/history`, `/help`, `/exit`
- Manage sessions: `new session`, `show sessions`, `rename session X to Y`, `pin 1`, `delete 2`
- Press `Ctrl+D` or type `/exit` to quit

The shell supports readline history, so you can use arrow keys to recall previous messages.

---

## Configuration Reference

All configuration is stored in the database (agents, teams, settings) and in `.env` for secrets and paths:

| Environment Variable | Description |
|---|---|
| `LARACLAW_DEFAULT_PROVIDER` | Default LLM provider (`anthropic`, `openai`, `google`, `ollama`, etc.) |
| `ANTHROPIC_API_KEY` | Anthropic API key (if using Claude) |
| `OPENAI_API_KEY` | OpenAI API key (if using GPT) |
| `LARACLAW_WORKSPACE_NAME` | Workspace directory name (default: `laraclaw-workspace`) |
| `LARACLAW_WORKSPACE_PATH` | Full workspace path |
| `LARACLAW_SERVER_API_KEY` | API key for WebSocket server authentication |
| `LARACLAW_REST_API_KEY` | API key for REST API authentication |
| `LARACLAW_HEARTBEAT_INTERVAL` | Agent heartbeat interval in seconds |
| `DISCORD_BOT_TOKEN` | Discord bot token (if using Discord channel) |

---

## Security

LaraClaw is, by design, a highly open-ended system capable of executing arbitrary skills and interacting with external 
APIs, LLMs, and user inputs. Because of this flexibility, it’s important to follow best practices to keep your system secure:

If you can, run it on a dedicated machine. If you can't do that, we suggest you take the following precautions to 
establish a strong security baseline. 

- Run as a dedicated system user: create a non-privileged user specifically for LaraClaw to limit the impact of any potential compromise.
- Use containerization: running LaraClaw inside a Laravel Sail container provides isolation from the host system.
- Use a firewall: restrict network access to only necessary ports using tools like Ubuntu UFW
- Secrets management: keep API keys, LLM tokens, and other sensitive credentials in environment variables (.env). Never commit secrets.
- Limit public exposure – avoid binding servers to 0.0.0.0 unless necessary; consider using a reverse proxy or VPN for external access.
- Monitor logs – track queue activity, skill execution, and API calls for unusual behavior or errors.
- Regular updates – keep PHP, Laravel, and system dependencies up to date to minimize vulnerabilities.

It is our strong advice to **not** give LaraClaw access to any of the following: 

- Your regular home directory
- Your online Mail, Document Storage and/or any other accounts that may have personal or sensitive data
- Your github account
- Your crypto wallet or online banking account
- Any other accounts that you use for personal or work processes

Following these steps helps ensure that LaraClaw remains both flexible and safe to run, even in open-ended or experimental setups.

---

## License

MIT
