<?php

namespace PNScripts\SheafgateLite\Reader;

use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;

/**
 * The work one reader (one request) may do, counted after expansion, never by what the
 * file claims. Every XML node the readers step over counts (elements, end tags, text,
 * whitespace, comments, processing instructions), every row element and every cell,
 * repeated ODS cells and rows counted as often as they are expanded.
 *
 * Nodes are allowed in proportion to what they give: a fixed base, plus NODES_PER_VALUE for
 * every cell value read, NODES_PER_STRING for every shared string item read and one for every
 * two empty cell elements next to real values (at most twice what the values allow: a flood of
 * empty cells with no values gets nothing), never more than $maxNodes. A real sheet
 * spends 3 to 10 nodes per value; a flood of junk elements, whitespace or empty markup gives
 * nothing and is refused after the base, and junk next to real values runs out at about twice
 * the work of a real file with the same values.
 *
 * Time is the backstop for everything a node count cannot weigh: one reader may run
 * $seconds plus $secondsPerValue for every value read (a real sheet needs about a quarter of
 * that), checked every CLOCK_EVERY nodes.
 *
 * Comments and processing instructions have a much smaller budget of their own: libxml's
 * XMLReader keeps every one it has passed in memory (outside PHP's memory_limit) until the
 * document is closed, and no real spreadsheet part holds more than a handful.
 *
 * The counters are public properties and checked inline: they run for every node.
 */
final class Budget
{
    /** Nodes allowed without anything to show for them (see the class description). */
    public const NODE_BASE = 4000000;

    /** About twice what a real cell value costs (an inline string is 7 nodes, a number 5). */
    public const NODES_PER_VALUE = 12;

    /** A shared string item read: a plain one is 4 or 5 nodes, rich text with formatted runs 20 to 30. */
    public const NODES_PER_STRING = 32;

    /** How often (in nodes) the clock is read: about every 10 ms. */
    public const CLOCK_EVERY = 16384;

    public int $nodes = 0;

    /** The nodes allowed now: grows with every value, string and pair of empty cells, up to $maxNodes. */
    public int $allowance;

    /** Where count() next stops to look: the allowance, or the next clock check if sooner. */
    public int $limit;

    public int $values = 0;

    public int $strings = 0;

    public int $empties = 0;

    public int $markup = 0;

    public int $rows = 0;

    /** Cell elements in halves: a cell with content counts two, an empty cell element one. */
    public int $cells = 0;

    /** hrtime() when the budget was made, in nanoseconds. */
    private readonly int $started;

    private int $nextClock = self::CLOCK_EVERY;

    public function __construct(
        public readonly int $maxNodes,
        public readonly int $maxMarkup,
        public readonly int $maxRows,
        public readonly int $maxCells,
        private readonly int $rowLimit,
        public readonly float $seconds = 10.0,
        public readonly float $secondsPerValue = 0.000008,
    ) {
        $this->started = hrtime(true);
        $this->allowance = min($this->maxNodes, self::NODE_BASE);
        $this->limit = min($this->allowance, $this->nextClock);
    }

    /**
     * Counts a cell value read, which allows NODES_PER_VALUE more nodes.
     */
    public function value(): void
    {
        $this->values++;
        $this->grow();
    }

    /**
     * Counts a shared string item read, which allows NODES_PER_STRING more nodes.
     */
    public function stringItem(): void
    {
        $this->strings++;
        $this->grow();
    }

    /**
     * Counts an empty cell element (<c r="B2" s="1"/>): half a cell, and half the node it is
     * (a formatted template has them by the thousand next to its values; a flood of them
     * without values gets no nodes for them).
     */
    public function emptyCell(): void
    {
        if (++$this->cells > 2 * $this->maxCells) {
            throw UnreadableSpreadsheet::because('too_many_cells', replace: ['max' => number_format($this->maxCells)]);
        }

        $this->empties++;
        $this->grow();
    }

    private function grow(): void
    {
        $this->allowance = min($this->maxNodes, self::NODE_BASE + self::NODES_PER_VALUE * $this->values + self::NODES_PER_STRING * $this->strings + min(intdiv($this->empties, 2), 2 * self::NODES_PER_VALUE * $this->values));
        $this->limit = min($this->allowance, $this->nextClock);
    }

    /**
     * Called by XmlStream::count() when the node count reaches $limit: refuses the reader when
     * its nodes or its time are spent, otherwise sets the next stop.
     */
    public function checkpoint(): void
    {
        if ($this->nodes > $this->allowance) {
            throw UnreadableSpreadsheet::because('too_complex');
        }

        if ($this->nodes >= $this->nextClock) {
            $this->nextClock = $this->nodes + self::CLOCK_EVERY;
            $this->checkClock();
        }

        $this->limit = min($this->allowance, $this->nextClock);
    }

    /**
     * Refuses the reader when it has run longer than its time allows.
     */
    public function checkClock(): void
    {
        $elapsed = (hrtime(true) - $this->started) / 1e9;

        if ($elapsed > $this->seconds + $this->secondsPerValue * $this->values) {
            throw UnreadableSpreadsheet::because('too_complex');
        }
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

    /**
     * Counts cells with content (or repeated copies of one), each at full weight.
     */
    public function cells(int $count = 1): void
    {
        if (($this->cells += 2 * $count) > 2 * $this->maxCells) {
            throw UnreadableSpreadsheet::because('too_many_cells', replace: ['max' => number_format($this->maxCells)]);
        }
    }
}
