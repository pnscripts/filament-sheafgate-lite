<?php

namespace PNScripts\SheafgateLite\Reader;

use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;

/**
 * The work one reader (one request) may do, counted after expansion, never by what the
 * file claims. Every XML node the readers step over counts (elements, end tags, text,
 * whitespace, comments, processing instructions), every row element and every cell,
 * repeated ODS cells and rows counted as often as they are expanded.
 *
 * Nodes are allowed in proportion to the values they give: a fixed base plus NODES_PER_VALUE
 * for every cell value read, never more than $maxNodes. A real sheet spends 3 to 10 nodes
 * per value (rich text a few more); a flood of junk elements, whitespace or empty markup gives
 * no values and is refused after the base, in about two seconds, instead of being walked to
 * the absolute cap.
 *
 * Comments and processing instructions have a much smaller budget of their own: libxml's
 * XMLReader keeps every one it has passed in memory (outside PHP's memory_limit) until the
 * document is closed, and no real spreadsheet part holds more than a handful.
 *
 * The counters are public properties and checked inline: they run for every node.
 */
final class Budget
{
    /** Nodes allowed so far without a value (see the class description). */
    public const NODE_BASE = 4000000;

    public const NODES_PER_VALUE = 24;

    public int $nodes = 0;

    /** The nodes allowed now: grows with every value, up to $maxNodes. */
    public int $allowance;

    public int $values = 0;

    public int $markup = 0;

    public int $rows = 0;

    public int $cells = 0;

    public function __construct(
        public readonly int $maxNodes,
        public readonly int $maxMarkup,
        public readonly int $maxRows,
        public readonly int $maxCells,
        private readonly int $rowLimit,
    ) {
        $this->allowance = min($this->maxNodes, self::NODE_BASE);
    }

    /**
     * Counts a cell value read, which allows NODES_PER_VALUE more nodes.
     */
    public function value(): void
    {
        $this->values++;
        $this->allowance = min($this->maxNodes, self::NODE_BASE + self::NODES_PER_VALUE * $this->values);
    }

    public function nodesSpent(): UnreadableSpreadsheet
    {
        return UnreadableSpreadsheet::because('too_complex');
    }

    /**
     * Counts a comment or processing instruction (also counted as a node by the caller).
     */
    public function markup(): void
    {
        if (++$this->markup > $this->maxMarkup) {
            throw UnreadableSpreadsheet::because('too_complex');
        }
    }

    public function row(int $count = 1): void
    {
        if (($this->rows += $count) > $this->maxRows) {
            throw UnreadableSpreadsheet::because('too_many_rows', replace: ['max' => number_format($this->rowLimit)]);
        }
    }

    public function cells(int $count = 1): void
    {
        if (($this->cells += $count) > $this->maxCells) {
            throw UnreadableSpreadsheet::because('too_many_cells', replace: ['max' => number_format($this->maxCells)]);
        }
    }
}
