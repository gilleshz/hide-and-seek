package fr.gshz.hideandseek.core.util

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.doubleOrNull

private val BASE64URL_INDEX: Map<Char, Int> = buildMap {
    "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_".forEachIndexed { index, char ->
        put(char, index)
    }
}

private const val BITS_PER_SEXTET = 6
private const val BITS_PER_BYTE = 8
private const val BASE64_GROUP_SIZE = 4
private const val JWT_SEGMENT_COUNT = 3
private const val BYTE_MASK = 0xFF
private const val HEADER_SEGMENT = 0
private const val PAYLOAD_SEGMENT = 1
private const val MERCURE_V1_TYP = "at+jwt"

/**
 * Returns the JWT `exp` claim in epoch seconds, or null when the token is not a well-formed
 * three-segment JWT with a numeric expiry. Server-minted tokens use a decimal `exp`, so both
 * decimal and integer numerics are accepted and truncated to whole seconds.
 */
fun String.jwtExpEpochSeconds(json: Json = Json): Long? =
    jwtJsonSegment(PAYLOAD_SEGMENT, json)
        ?.get("exp")
        ?.let { (it as? JsonPrimitive)?.doubleOrNull?.toLong() }

/**
 * True when the JWT header declares `typ: at+jwt`, the Mercure 1.0 access-token header. Tokens
 * without it are legacy subscriber tokens, and anything unparseable counts as legacy too.
 */
fun String.isMercureV1AccessToken(json: Json = Json): Boolean =
    jwtJsonSegment(HEADER_SEGMENT, json)
        ?.get("typ")
        ?.let { (it as? JsonPrimitive)?.content == MERCURE_V1_TYP } == true

private fun String.jwtJsonSegment(index: Int, json: Json): JsonObject? {
    val segments = split('.')
    val decoded = if (segments.size == JWT_SEGMENT_COUNT) decodeBase64Url(segments[index]) else null
    return decoded?.let { segment ->
        try {
            json.parseToJsonElement(segment) as? JsonObject
        } catch (_: IllegalArgumentException) {
            null
        }
    }
}

// Self-contained base64url decoder: android.util.Base64 is unavailable to JVM tests, java.util.Base64 needs API 26.
private fun decodeBase64Url(input: String): String? {
    val cleaned = input.filterNot { it == '=' }
    if (cleaned.isEmpty() || cleaned.length % BASE64_GROUP_SIZE == 1) return null
    val bytes = ArrayList<Byte>(cleaned.length * BITS_PER_SEXTET / BITS_PER_BYTE)
    var accumulator = 0
    var bits = 0
    var invalid = false
    for (char in cleaned) {
        val value = BASE64URL_INDEX[char]
        if (value == null) {
            invalid = true
            break
        }
        accumulator = (accumulator shl BITS_PER_SEXTET) or value
        bits += BITS_PER_SEXTET
        if (bits >= BITS_PER_BYTE) {
            bits -= BITS_PER_BYTE
            bytes.add(((accumulator shr bits) and BYTE_MASK).toByte())
            accumulator = accumulator and ((1 shl bits) - 1)
        }
    }
    return if (invalid) null else bytes.toByteArray().toString(Charsets.UTF_8)
}
