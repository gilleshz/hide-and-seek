package fr.gshz.hideandseek.core.util

import java.util.Base64
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Test

class JwtTest {

    private fun b64url(input: String): String =
        Base64.getUrlEncoder().withoutPadding().encodeToString(input.toByteArray())

    private fun token(payload: String, segments: Int = 3): String {
        val parts = listOf(b64url("""{"alg":"HS256"}"""), b64url(payload), "signature")
        val adjusted = when {
            segments < JWT_SEGMENT_COUNT -> parts.take(segments)
            segments > JWT_SEGMENT_COUNT -> parts + "extra"
            else -> parts
        }
        return adjusted.joinToString(".")
    }

    private companion object {
        const val JWT_SEGMENT_COUNT = 3
    }

    @Test
    fun `a token with a numeric exp returns it in epoch seconds`() {
        val payload = buildJsonObject { put("exp", 1_752_000_000L) }

        assertEquals(1_752_000_000L, token(payload.toString()).jwtExpEpochSeconds())
    }

    @Test
    fun `a token without an exp claim returns null`() {
        val payload = buildJsonObject { put("sub", "player-1") }

        assertNull(token(payload.toString()).jwtExpEpochSeconds())
    }

    @Test
    fun `a token whose exp is not a number returns null`() {
        val payload = buildJsonObject { put("exp", "soon") }

        assertNull(token(payload.toString()).jwtExpEpochSeconds())
    }

    @Test
    fun `garbage returns null`() {
        assertNull("not-a-jwt".jwtExpEpochSeconds())
        assertNull("a.b.c".jwtExpEpochSeconds())
    }

    @Test
    fun `a token with the wrong segment count returns null`() {
        val payload = buildJsonObject { put("exp", 1_752_000_000L) }

        assertNull(token(payload.toString(), segments = 2).jwtExpEpochSeconds())
        assertNull(token(payload.toString(), segments = 4).jwtExpEpochSeconds())
    }

    @Test
    fun `a non-JSON payload returns null`() {
        assertNull(token("plain text payload").jwtExpEpochSeconds())
    }

    @Test
    fun `an empty payload segment returns null`() {
        val parts = listOf(b64url("""{"alg":"HS256"}"""), "", "signature")
        assertNull(parts.joinToString(".").jwtExpEpochSeconds())
    }

    @Test
    fun `a payload with trailing padding still parses`() {
        val payload = buildJsonObject { put("exp", 1_752_000_000L) }
        val padded = Base64.getUrlEncoder().encodeToString(payload.toString().toByteArray())

        assertEquals(1_752_000_000L, "header.$padded.signature".jwtExpEpochSeconds())
    }

    @Test
    fun `a non-JSON object payload returns null`() {
        val payload = """["an", "array"]"""

        assertNull(token(payload).jwtExpEpochSeconds())
    }

    @Test
    fun `a null exp claim returns null`() {
        val payload = Json.parseToJsonElement("""{"exp":null}""") as JsonObject

        assertNull(token(payload.toString()).jwtExpEpochSeconds())
    }
}
