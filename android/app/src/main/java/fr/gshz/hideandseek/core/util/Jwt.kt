package fr.gshz.hideandseek.core.util

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.longOrNull

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

/**
 * Returns the JWT `exp` claim in epoch seconds, or null when the token is not a well-formed
 * three-segment JWT with a numeric expiry.
 */
fun String.jwtExpEpochSeconds(json: Json = Json): Long? {
    val segments = split('.')
    val payload = if (segments.size == JWT_SEGMENT_COUNT) decodeBase64Url(segments[1]) else null
    if (payload == null) return null
    return try {
        val obj = json.parseToJsonElement(payload) as? JsonObject
        val exp = obj?.get("exp") as? JsonPrimitive
        exp?.longOrNull
    } catch (_: IllegalArgumentException) {
        null
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
