# Claude Configuration for Djebel Project

This directory contains Claude Code configuration for the Djebel PHP Framework project.

## Files

### `instructions.md`
Project-specific instructions that Claude will read when working on this project. Contains:
- Project overview and structure
- Quick reference to common classes
- Workflow guidelines
- Testing notes

### `prompts/djebel-coding-guide.md`
Comprehensive coding standards guide covering:
- Code style rules (spacing, operators, variable evaluation)
- Framework-specific methods and utilities
- Architecture patterns (copy-extend-filter, array-based construction, hooks)
- Performance optimization techniques
- Feature implementation guidelines (optional/on-demand features)
- Documentation requirements with examples
- Plugin architecture patterns

### `settings.local.json`
Tool permissions for Claude Code operations (auto-approve certain commands).

## Usage

When starting a new Claude conversation in this project:

1. Claude automatically reads `instructions.md`
2. Reference the coding guide for detailed patterns: `.claude/prompts/djebel-coding-guide.md`
3. Follow the established patterns consistently

## Updating Guidelines

When you discover new patterns or conventions:

1. Add them to `djebel-coding-guide.md` with examples
2. Update `instructions.md` if it affects workflow
3. Keep both files in sync

## Tips

- Always check the coding guide before implementing new features
- Use the framework's utility methods instead of PHP builtins
- Follow the copy-extend-filter pattern for parameter processing
- Add filter hooks for extensibility
- Document optional features with concrete examples
