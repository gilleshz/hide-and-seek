package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.domain.model.LocationUpdate
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Test

class MapAvatarTest {

    @Test
    fun `the roster avatar url reaches the marker`() {
        val markers = buildMarkers(
            roster = mapOf("player-1" to RosterPlayer("Alice", "https://maps.test/api/avatars/ref-1")),
            selfUuid = "player-1",
            locationMap = mapOf("player-1" to location("player-1")),
            selfGps = null,
        )

        assertEquals("https://maps.test/api/avatars/ref-1", markers.single().avatarUrl)
    }

    @Test
    fun `a player missing from the roster keeps the uuid and no avatar`() {
        val markers = buildMarkers(
            roster = emptyMap(),
            selfUuid = null,
            locationMap = mapOf("player-2" to location("player-2")),
            selfGps = null,
        )

        assertEquals("player-2", markers.single().displayName)
        assertNull(markers.single().avatarUrl)
    }

    @Test
    fun `avatar pins use one image id per player`() {
        val self = marker("self-player", isSelf = true, avatarUrl = "https://maps.test/api/avatars/self-ref")
        val other = marker("other-player", isSelf = false, avatarUrl = "https://maps.test/api/avatars/other-ref")

        assertEquals(
            avatarImageId("self-player", "https://maps.test/api/avatars/self-ref"),
            pinImageId(self, "https://maps.test/api/avatars/self-ref"),
        )
        assertEquals(
            avatarImageId("other-player", "https://maps.test/api/avatars/other-ref"),
            pinImageId(other, "https://maps.test/api/avatars/other-ref"),
        )
        assertEquals("marker-self", pinImageId(self, null))
        assertEquals("marker-other", pinImageId(other, null))
    }

    private fun location(playerUuid: String) =
        LocationUpdate(playerUuid, 48.0, 2.0, "2026-01-01T00:00:00Z")

    private fun marker(
        playerUuid: String,
        isSelf: Boolean,
        avatarUrl: String? = null,
    ) = PlayerMarker(
        playerUuid = playerUuid,
        displayName = playerUuid,
        initials = "P",
        latitude = 48.0,
        longitude = 2.0,
        isSelf = isSelf,
        avatarUrl = avatarUrl,
    )
}
