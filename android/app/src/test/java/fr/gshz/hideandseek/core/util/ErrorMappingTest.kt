package fr.gshz.hideandseek.core.util

import fr.gshz.hideandseek.fake.httpException
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class ErrorMappingTest {

    private fun identityBody(key: String) =
        """{"type":"about:blank","title":"Unauthorized","errorKey":"$key","detail":"rejected"}"""

    @Test
    fun `identity token errors count as session expired`() {
        listOf(
            "identity.token_missing",
            "identity.token_invalid",
            "identity.player_not_found",
            "identity.player_left",
        ).forEach { key ->
            assertTrue(httpException(401, identityBody(key)).isSessionExpired())
        }
    }

    @Test
    fun `a 403 with an identity key counts as session expired`() {
        assertTrue(httpException(403, identityBody("identity.token_invalid")).isSessionExpired())
    }

    @Test
    fun `an api key 401 without an error key is not session expired`() {
        assertFalse(httpException(401).isSessionExpired())
    }

    @Test
    fun `a 401 with a non identity error key is not session expired`() {
        assertFalse(httpException(401, identityBody("game.name_taken")).isSessionExpired())
    }

    @Test
    fun `an identity key outside the 401 to 403 range is not session expired`() {
        assertFalse(httpException(400, identityBody("identity.token_invalid")).isSessionExpired())
    }

    @Test
    fun `a rate limit 429 is not session expired`() {
        assertFalse(httpException(429, identityBody("rate_limit.exceeded")).isSessionExpired())
    }

    @Test
    fun `a non json error body is not session expired`() {
        assertFalse(httpException(401, "not-json").isSessionExpired())
    }
}
