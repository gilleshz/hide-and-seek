package fr.gshz.hideandseek.core.location

import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class LocationHeartbeatTest {

    @Test
    fun `not due just before the interval`() {
        assertFalse(LocationHeartbeat.isDue(nowMs = 59_999, lastPostedMs = 0, intervalMs = 60_000))
    }

    @Test
    fun `due exactly at the interval`() {
        assertTrue(LocationHeartbeat.isDue(nowMs = 60_000, lastPostedMs = 0, intervalMs = 60_000))
    }

    @Test
    fun `due after the interval`() {
        assertTrue(LocationHeartbeat.isDue(nowMs = 60_001, lastPostedMs = 0, intervalMs = 60_000))
    }

    @Test
    fun `does not repost a fix that has gone stale`() {
        assertFalse(
            LocationHeartbeat.canRepost(
                nowMs = 120_000,
                lastPostedMs = 0,
                lastFixReceivedAtMs = 0,
                maxFixAgeMs = 70_000,
            ),
        )
    }

    @Test
    fun `does not repost a fix older than the freshness window`() {
        assertFalse(
            LocationHeartbeat.canRepost(
                nowMs = 100_000,
                lastPostedMs = 0,
                lastFixReceivedAtMs = 20_000,
                maxFixAgeMs = 70_000,
            ),
        )
    }

    @Test
    fun `reposts a fix at the freshness boundary`() {
        assertTrue(
            LocationHeartbeat.canRepost(
                nowMs = 100_000,
                lastPostedMs = 0,
                lastFixReceivedAtMs = 30_000,
                maxFixAgeMs = 70_000,
            ),
        )
    }

    @Test
    fun `does not repost before the heartbeat interval`() {
        assertFalse(
            LocationHeartbeat.canRepost(
                nowMs = 30_000,
                lastPostedMs = 10_000,
                lastFixReceivedAtMs = 25_000,
                maxFixAgeMs = 70_000,
            ),
        )
    }
}
