<?php

use ManticoreLaravel\Builder\Grammar\ManticoreGrammar;

// ---------------------------------------------------------------------------
// compileFieldReference
// ---------------------------------------------------------------------------

it('backtick-quotes simple field names', function () {
    expect(ManticoreGrammar::compileFieldReference('title'))->toBe('`title`');
});

it('does not quote fields with spaces or parentheses', function () {
    expect(ManticoreGrammar::compileFieldReference('COUNT(*)'))->toBe('COUNT(*)');
    expect(ManticoreGrammar::compileFieldReference('a b'))->toBe('a b');
});

it('does not quote fields with dots', function () {
    expect(ManticoreGrammar::compileFieldReference('a.b'))->toBe('a.b');
});

// ---------------------------------------------------------------------------
// toSqlWhereClauseFromSequence
// ---------------------------------------------------------------------------

it('returns empty string for empty sequence', function () {
    expect(ManticoreGrammar::toSqlWhereClauseFromSequence([]))->toBe('');
});

it('compiles MATCH clause from match array', function () {
    $match = [['field' => '*', 'keywords' => 'hello', 'boolean' => 'AND']];
    $clause = ManticoreGrammar::toSqlWhereClauseFromSequence([], $match);
    expect($clause)->toContain("MATCH('@* hello')");
});

it('compiles equals condition', function () {
    $condition = new \Manticoresearch\Query\Equals('status', 'active');
    $sequence  = [['boolean' => 'and', 'negated' => false, 'condition' => $condition]];
    $clause    = ManticoreGrammar::toSqlWhereClauseFromSequence($sequence);
    expect($clause)->toContain("`status` = 'active'");
});

it('compiles negated equals condition as <>', function () {
    $condition = new \Manticoresearch\Query\Equals('status', 'deleted');
    $sequence  = [['boolean' => 'and', 'negated' => true, 'condition' => $condition]];
    $clause    = ManticoreGrammar::toSqlWhereClauseFromSequence($sequence);
    expect($clause)->toContain("`status` <> 'deleted'");
});

it('compiles IN condition', function () {
    $condition = new \Manticoresearch\Query\In('type', ['a', 'b']);
    $sequence  = [['boolean' => 'and', 'negated' => false, 'condition' => $condition]];
    $clause    = ManticoreGrammar::toSqlWhereClauseFromSequence($sequence);
    expect($clause)->toContain("`type` IN ('a', 'b')");
});

it('compiles NOT IN condition', function () {
    $condition = new \Manticoresearch\Query\In('type', ['spam', 'banned']);
    $sequence  = [['boolean' => 'and', 'negated' => true, 'condition' => $condition]];
    $clause    = ManticoreGrammar::toSqlWhereClauseFromSequence($sequence);
    expect($clause)->toContain("`type` NOT IN ('spam', 'banned')");
});

it('compiles range gte condition', function () {
    $condition = new \Manticoresearch\Query\Range('score', ['gte' => 10]);
    $sequence  = [['boolean' => 'and', 'negated' => false, 'condition' => $condition]];
    $clause    = ManticoreGrammar::toSqlWhereClauseFromSequence($sequence);
    expect($clause)->toContain('`score` >= 10');
});

it('compiles raw condition directly', function () {
    $sequence = [['boolean' => 'AND', 'negated' => false, 'condition' => null, 'raw' => 'score > 100']];
    $clause   = ManticoreGrammar::toSqlWhereClauseFromSequence($sequence);
    expect($clause)->toContain('score > 100');
});

it('joins AND conditions correctly', function () {
    $c1       = new \Manticoresearch\Query\Equals('a', 1);
    $c2       = new \Manticoresearch\Query\Equals('b', 2);
    $sequence = [
        ['boolean' => 'and', 'negated' => false, 'condition' => $c1],
        ['boolean' => 'and', 'negated' => false, 'condition' => $c2],
    ];
    $clause   = ManticoreGrammar::toSqlWhereClauseFromSequence($sequence);
    expect($clause)->toContain('AND');
});

it('joins OR conditions correctly', function () {
    $c1       = new \Manticoresearch\Query\Equals('status', 'active');
    $c2       = new \Manticoresearch\Query\Equals('status', 'pending');
    $sequence = [
        ['boolean' => 'and', 'negated' => false, 'condition' => $c1],
        ['boolean' => 'or',  'negated' => false, 'condition' => $c2],
    ];
    $clause   = ManticoreGrammar::toSqlWhereClauseFromSequence($sequence);
    expect($clause)->toContain('OR');
});
