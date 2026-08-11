package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class LocationDtoTest {

    private val json = Json { ignoreUnknownKeys = true }

    @Test
    fun `a ping with an altitude serializes the altitude field`() {
        val encoded = json.encodeToString(
            LocationPingRequest(playerUuid = "player-1", lat = 12.3, lng = 45.6, altitude = 210.5),
        )

        assertTrue(encoded.contains("\"altitude\":210.5"))
    }

    @Test
    fun `a ping without an altitude omits the field`() {
        val encoded = json.encodeToString(
            LocationPingRequest(playerUuid = "player-1", lat = 12.3, lng = 45.6),
        )

        assertFalse(encoded.contains("altitude"))
    }
}
