package fr.gshz.hideandseek.core.ui

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

class DurationFormatTest {

    @Test
    fun `under an hour it stays minutes and seconds`() {
        assertEquals("0:07", formatDuration(7))
        assertEquals("59:59", formatDuration(3599))
    }

    @Test
    fun `an hour and beyond grows an hours field`() {
        assertEquals("1:00:00", formatDuration(3600))
        assertEquals("1:32:02", formatDuration(5522))
        assertEquals("13:00:01", formatDuration(46801))
    }

    @Test
    fun `a negative duration reads as zero rather than as a negative clock`() {
        assertEquals("0:00", formatDuration(-90))
    }

    @Test
    fun `the split the worded form is built from carries hours, minutes and seconds apart`() {
        assertEquals(DurationParts(0, 0, 7), durationParts(7))
        assertEquals(DurationParts(0, 59, 59), durationParts(3599))
        assertEquals(DurationParts(1, 0, 0), durationParts(3600))
        assertEquals(DurationParts(2, 14, 8), durationParts(8048))
        assertEquals(DurationParts(0, 0, 0), durationParts(-90))
    }
}
