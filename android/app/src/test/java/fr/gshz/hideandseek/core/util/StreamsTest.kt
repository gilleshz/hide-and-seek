package fr.gshz.hideandseek.core.util

import java.io.ByteArrayInputStream
import java.io.ByteArrayOutputStream
import org.junit.jupiter.api.Assertions.assertArrayEquals
import org.junit.jupiter.api.Assertions.assertThrows
import org.junit.jupiter.api.Test

class StreamsTest {

    private fun bytes(size: Int) = ByteArray(size) { (it % 251).toByte() }

    @Test
    fun `copyCapped copies an exact-size stream`() {
        val input = ByteArrayInputStream(bytes(1024))
        val output = ByteArrayOutputStream()

        input.copyCapped(output, 1024)

        assertArrayEquals(bytes(1024), output.toByteArray())
    }

    @Test
    fun `copyCapped throws when the stream exceeds the cap`() {
        val input = ByteArrayInputStream(bytes(1025))

        assertThrows(StreamLimitExceededException::class.java) {
            input.copyCapped(ByteArrayOutputStream(), 1024)
        }
    }

    @Test
    fun `copyCapped copies an empty stream`() {
        val input = ByteArrayInputStream(ByteArray(0))
        val output = ByteArrayOutputStream()

        input.copyCapped(output, 10)

        assertArrayEquals(ByteArray(0), output.toByteArray())
    }

    @Test
    fun `copyCapped with a zero cap throws on any data`() {
        val input = ByteArrayInputStream(bytes(1))

        assertThrows(StreamLimitExceededException::class.java) {
            input.copyCapped(ByteArrayOutputStream(), 0)
        }
    }

    @Test
    fun `readCapped returns the exact bytes within the cap`() {
        val input = ByteArrayInputStream(bytes(2048))

        assertArrayEquals(bytes(2048), input.readCapped(2048))
    }

    @Test
    fun `readCapped throws when the stream exceeds the cap`() {
        val input = ByteArrayInputStream(bytes(2049))

        assertThrows(StreamLimitExceededException::class.java) { input.readCapped(2048) }
    }
}
