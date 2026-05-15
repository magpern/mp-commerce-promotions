# Commerce Promotions for WooCommerce

A **lightweight promotion engine** for WooCommerce. This repository is intended as a **generic WooCommerce extension**, not a store-specific plugin.

**Repository:** [https://github.com/magpern/mp-commerce-promotions](https://github.com/magpern/mp-commerce-promotions)

## Purpose

Provide a structured foundation for commerce promotions using:

- **Conditions** — when a promotion may apply  
- **Actions** — what the promotion does  
- **Restrictions** — limits and guardrails  
- **Evaluation pipeline** — how rules are resolved at runtime (planned)

## Status

**Early development / scaffold phase.** The codebase is **not production-feature-complete** yet (no full promotion evaluation, cart wiring, or data persistence in the current release).

## Requirements

- WordPress 6.4+
- PHP 7.4+
- WooCommerce (recommended for admin integration and future features)

## Install (development)

Copy this folder into `wp-content/plugins/`, then activate **MP Commerce Promotions** in the WordPress admin.
