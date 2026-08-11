package fr.gshz.hideandseek.core.location

import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class PingBackoffTest {

    private var nowMs = 0L
    private val backoff = PingBackoff { nowMs }

    @Test
    fun `is inactive before any failure`() {
        assertFalse(backoff.active)
    }

    @Test
    fun `a failure blocks pings for the base delay`() {
        backoff.recordFailure()

        assertTrue(backoff.active)
        nowMs = 999
        assertTrue(backoff.active)
        nowMs = 1_000
        assertFalse(backoff.active)
    }

    @Test
    fun `repeated failures grow the delay up to the cap`() {
        repeat(10) { backoff.recordFailure() }

        nowMs = 29_999
        assertTrue(backoff.active)
        nowMs = 30_000
        assertFalse(backoff.active)
    }

    @Test
    fun `reset clears the backoff window`() {
        backoff.recordFailure()
        backoff.reset()

        assertFalse(backoff.active)
    }
}
