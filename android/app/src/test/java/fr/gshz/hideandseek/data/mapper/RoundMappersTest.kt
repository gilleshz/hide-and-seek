package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.RoundDto
import fr.gshz.hideandseek.domain.model.RoundStatus
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Test

class RoundMappersTest {

    @Test
    fun `a known wire status maps to its enum value`() {
        val round = RoundDto(roundUuid = "round-1", status = "seeking").toDomain()

        assertEquals(RoundStatus.Seeking, round.status)
    }

    @Test
    fun `an unknown wire status maps to a null status instead of throwing`() {
        val round = RoundDto(roundUuid = "round-1", status = "intermission").toDomain()

        assertNull(round.status)
        assertEquals("round-1", round.roundUuid)
    }
}
