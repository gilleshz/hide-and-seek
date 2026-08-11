package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.TimeTrapDto
import fr.gshz.hideandseek.domain.model.TimeTrapStatus
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Test

class TimeTrapMappersTest {

    @Test
    fun `a full payload maps every field`() {
        val trap = TimeTrapDto(
            uuid = "trap-1",
            roundUuid = "round-1",
            stationName = "Flon",
            lat = 46.52,
            lng = 6.63,
            placedAt = "2026-07-31T10:00:00Z",
            status = "pending",
            valueSeconds = 240,
            intervalMinutes = 15,
            incrementMinutes = 4,
            detectedByName = "Bob",
            awardedSeconds = 240,
        ).toDomain()

        assertEquals("trap-1", trap.uuid)
        assertEquals("Flon", trap.stationName)
        assertEquals(TimeTrapStatus.Pending, trap.status)
        assertEquals(PLACED_AT_EPOCH_MS, trap.placedAtEpochMs)
        assertEquals("Bob", trap.detectedByName)
        assertEquals(240, trap.awardedSeconds)
    }

    @Test
    fun `an omitted station, detector and award decode to null`() {
        val trap = TimeTrapDto(uuid = "trap-1").toDomain()

        assertNull(trap.stationName)
        assertNull(trap.detectedByName)
        assertNull(trap.awardedSeconds)
    }

    @Test
    fun `an unparseable anchor falls back to now rather than to the epoch`() {
        val trap = TimeTrapDto(uuid = "trap-1", placedAt = "not a date").toDomain(nowMillis = FALLBACK_MS)

        assertEquals(FALLBACK_MS, trap.placedAtEpochMs)
    }

    @Test
    fun `an unknown status reads as armed instead of crashing the list`() {
        assertEquals(TimeTrapStatus.Armed, TimeTrapDto(uuid = "t", status = "exploded").toDomain().status)
    }

    private companion object {
        const val PLACED_AT_EPOCH_MS = 1_785_492_000_000L
        const val FALLBACK_MS = 42L
    }
}
