# Online Examination System

A production style online examination platform built from scratch in PHP (PDO) and MySQL,
using a hand-rolled MVC architecture, no framework.

## Features (in progress)
- Role-based access: admin, lecturer, student
- Randomized question bank with per-attempt snapshots
- Server-side exam timing, auto-save, automatic MCQ grading
- Manual essay grading, analytics, and anti-cheat activity logging

## Stack
PHP 8 (PDO, prepared statements) · MySQL · Vanilla JS · Custom MVC

## Setup
1. Clone into your web root
2. Import the schema (see `database/schema.sql`)
3. Copy `config/config.example.php` to `config/config.php` and fill in credentials
4. Point your browser at `/public`