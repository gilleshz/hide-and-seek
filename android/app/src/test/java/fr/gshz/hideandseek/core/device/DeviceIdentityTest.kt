package fr.gshz.hideandseek.core.device

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNotEquals
import org.junit.jupiter.api.Test

class DeviceIdentityTest {

    @Test
    fun `deviceKeyFrom hashes a known Android ID to lowercase 64-char SHA-256`() {
        assertEquals(
            "87fece06eb296cc26800e69c4e12bb990eeb819bc518fd0069414bfca71a6277",
            deviceKeyFrom("test-android-id"),
        )
    }

    @Test
    fun `deviceKeyFrom is stable for the same input`() {
        assertEquals(deviceKeyFrom("same-android-id"), deviceKeyFrom("same-android-id"))
    }

    @Test
    fun `different Android IDs produce different device keys`() {
        assertNotEquals(deviceKeyFrom("android-id-a"), deviceKeyFrom("android-id-b"))
    }

    @Test
    fun `a null Android ID uses the random fallback seed`() {
        assertEquals("fallback-seed", credentialSeed(null, "fallback-seed"))
    }

    @Test
    fun `a blank Android ID uses the random fallback seed`() {
        assertEquals("fallback-seed", credentialSeed("", "fallback-seed"))
        assertEquals("fallback-seed", credentialSeed("   ", "fallback-seed"))
    }

    @Test
    fun `a present Android ID wins over the fallback`() {
        assertEquals("android-id", credentialSeed("android-id", "fallback-seed"))
    }
}
