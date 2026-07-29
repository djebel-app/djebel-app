# Claude Configuration for Djebel Project

This directory contains Claude Code configuration for the Djebel PHP Framework project.

## Files

### `CLAUDE.md`
Project-specific instructions that Claude reads automatically when working on this
project. Contains:
- Project overview and structure
- Coding standards (10x PHP developer rules)
- Quick reference to common classes
- Workflow guidelines
- Testing notes

### `benchmarking.md`
How to produce a perf number that holds up — why the host CLI lies (Xdebug loaded,
macOS syscall cost), the container command, standalone core-lib bootstrapping, reporting
minimums instead of medians, and proving an optimization is behaviour-preserving.
Read it before making or believing any perf claim.

### `TODO.md`
Outstanding work items and their priorities.

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

1. Claude automatically reads `CLAUDE.md`
2. Reference the coding guide for detailed patterns: `.claude/prompts/djebel-coding-guide.md`
3. Before any perf work, read `.claude/benchmarking.md`
4. Follow the established patterns consistently

## Updating Guidelines

When you discover new patterns or conventions:

1. Add them to `djebel-coding-guide.md` with examples
2. Update `CLAUDE.md` if it affects workflow
3. Keep both files in sync

## Tips

- Always check the coding guide before implementing new features
- Use the framework's utility methods instead of PHP builtins
- Follow the copy-extend-filter pattern for parameter processing
- Add filter hooks for extensibility
- Document optional features with concrete examples
