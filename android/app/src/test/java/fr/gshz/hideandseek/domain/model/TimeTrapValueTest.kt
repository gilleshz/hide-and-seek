package fr.gshz.hideandseek.domain.model

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

class TimeTrapValueTest {

    @Test
    fun `a trap is worth nothing for its whole first interval`() {
        assertEquals(0, trap().valueSecondsAt(FIFTEEN_MINUTES_MS - 1))
    }

    @Test
    fun `the value steps by the increment at every interval boundary`() {
        assertEquals(FOUR_MINUTES, trap().valueSecondsAt(FIFTEEN_MINUTES_MS))
        assertEquals(FOUR_MINUTES, trap().valueSecondsAt(FIFTEEN_MINUTES_MS * 2 - 1))
        assertEquals(FOUR_MINUTES * 2, trap().valueSecondsAt(FIFTEEN_MINUTES_MS * 2))
        assertEquals(FOUR_MINUTES * 8, trap().valueSecondsAt(FIFTEEN_MINUTES_MS * 8))
    }

    @Test
    fun `a pending trap reports the value the detection froze`() {
        val pending = trap().copy(status = TimeTrapStatus.Pending, valueSeconds = FOUR_MINUTES)

        assertEquals(FOUR_MINUTES, pending.valueSecondsAt(FIFTEEN_MINUTES_MS * 10))
    }

    @Test
    fun `a sprung trap reports what it was awarded rather than accruing on`() {
        val sprung = trap().copy(status = TimeTrapStatus.Sprung, valueSeconds = FOUR_MINUTES * 3)

        assertEquals(FOUR_MINUTES * 3, sprung.valueSecondsAt(FIFTEEN_MINUTES_MS * 20))
    }

    @Test
    fun `a clock behind the placement reads zero rather than a negative value`() {
        assertEquals(0, trap().valueSecondsAt(-FIFTEEN_MINUTES_MS))
    }

    @Test
    fun `a trap with no interval cannot divide and keeps the server's figure`() {
        val broken = trap().copy(intervalMinutes = 0, valueSeconds = FOUR_MINUTES)

        assertEquals(FOUR_MINUTES, broken.valueSecondsAt(FIFTEEN_MINUTES_MS * 4))
    }

    private fun trap() = TimeTrap(
        uuid = "trap-1",
        roundUuid = "round-1",
        stationName = "Flon",
        lat = 46.52,
        lng = 6.63,
        placedAtEpochMs = 0L,
        status = TimeTrapStatus.Armed,
        valueSeconds = 0,
        intervalMinutes = 15,
        incrementMinutes = 4,
    )

    private companion object {
        const val FIFTEEN_MINUTES_MS = 900_000L
        const val FOUR_MINUTES = 240
    }
}
