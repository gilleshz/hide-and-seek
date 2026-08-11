package fr.gshz.hideandseek.core.util

import java.io.ByteArrayOutputStream
import java.io.InputStream
import java.io.OutputStream

/**
 * Copies at most [maxBytes] bytes from [input] to [output]; throws
 * [StreamLimitExceededException] if the source stream is bigger.
 */
internal fun InputStream.copyCapped(output: OutputStream, maxBytes: Long) {
    var total = 0L
    val buffer = ByteArray(DEFAULT_BUFFER_SIZE)
    while (true) {
        val read = read(buffer)
        if (read == -1) break
        total += read
        if (total > maxBytes) throw StreamLimitExceededException()
        output.write(buffer, 0, read)
    }
}

/** Reads at most [maxBytes] bytes; throws [StreamLimitExceededException] if the source stream is bigger. */
internal fun InputStream.readCapped(maxBytes: Long): ByteArray =
    ByteArrayOutputStream().use { out ->
        copyCapped(out, maxBytes)
        out.toByteArray()
    }
