package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.device.DeviceIdentity
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.ImageParts
import fr.gshz.hideandseek.data.remote.dto.ProfileDto
import fr.gshz.hideandseek.data.remote.dto.ProfileNameRequest
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.every
import io.mockk.mockk
import io.mockk.slot
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import okio.Buffer
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

class AvatarRepositoryImplTest {

    private val api = mockk<HideAndSeekApi>()
    private val connectionStore = mockk<ConnectionStore>()
    private val imageParts = mockk<ImageParts>()
    private val repository = AvatarRepositoryImpl(
        api,
        connectionStore,
        AvatarFakeDeviceIdentity,
        imageParts,
        Json { ignoreUnknownKeys = true },
    )

    @BeforeEach
    fun setUp() {
        coEvery { connectionStore.current() } returns ConnectionConfig("https://api.example.com/", "key")
    }

    @Test
    fun `getProfile builds the avatar URL from the stored API URL`() = runTest {
        coEvery {
            api.getProfile("https://api.example.com/api/profile", "test-device-key")
        } returns ProfileDto("uuid-1", "Alice", "ref-1")

        val profile = repository.getProfile()

        assertEquals("uuid-1", profile.uuid)
        assertEquals("Alice", profile.name)
        assertEquals("ref-1", profile.avatarRef)
        assertEquals("https://api.example.com/api/avatars/ref-1", profile.avatarUrl)
    }

    @Test
    fun `getProfile leaves the avatar URL null without a ref`() = runTest {
        coEvery { api.getProfile(any(), any()) } returns ProfileDto("uuid-1", "Alice")

        val profile = repository.getProfile()

        assertNull(profile.avatarRef)
        assertNull(profile.avatarUrl)
    }

    @Test
    fun `getProfile without a connection throws NotConnectedException`() = runTest {
        coEvery { connectionStore.current() } returns null

        val result = runCatching { repository.getProfile() }

        assertTrue(result.exceptionOrNull() is NotConnectedException)
    }

    @Test
    fun `bootstrap sends the display name and returns the server profile`() = runTest {
        coEvery {
            api.bootstrapProfile(
                "https://api.example.com/api/profile",
                "test-device-key",
                ProfileNameRequest("Alice"),
            )
        } returns ProfileDto("uuid-1", "Alice", null)

        val profile = repository.bootstrap("Alice")

        assertEquals("uuid-1", profile.uuid)
        assertEquals("Alice", profile.name)
    }

    @Test
    fun `rename sends a merge patch body and returns the server profile`() = runTest {
        val body = slot<RequestBody>()
        coEvery {
            api.renameProfile("https://api.example.com/api/profile", "test-device-key", capture(body))
        } returns ProfileDto("uuid-1", "Bob", null)

        val profile = repository.rename("Bob")

        assertEquals("Bob", profile.name)
        assertEquals(
            "application/merge-patch+json",
            body.captured.contentType()?.let { "${it.type}/${it.subtype}" },
        )
        val buffer = Buffer()
        body.captured.writeTo(buffer)
        assertEquals("""{"name":"Bob"}""", buffer.readUtf8())
    }

    @Test
    fun `profile calls do not need a stored player name`() = runTest {
        coEvery { api.getProfile(any(), any()) } returns ProfileDto("uuid-1", "Alice")

        val profile = repository.getProfile()

        assertEquals("Alice", profile.name)
    }

    @Test
    fun `uploadAvatar sends the jpeg and returns the profile with its URL`() = runTest {
        val jpeg = byteArrayOf(1, 2, 3)
        val part = MultipartBody.Part.createFormData(
            "image",
            "avatar.jpg",
            jpeg.toRequestBody("image/jpeg".toMediaType()),
        )
        every { imageParts.image(jpeg) } returns part
        coEvery {
            api.uploadAvatar("https://api.example.com/api/profile/avatar", "test-device-key", part)
        } returns ProfileDto("uuid-1", "Alice", "ref-2")

        val profile = repository.uploadAvatar(jpeg)

        assertEquals("ref-2", profile.avatarRef)
        assertEquals("https://api.example.com/api/avatars/ref-2", profile.avatarUrl)
    }

    @Test
    fun `uploadAvatar fails when the server returns no ref`() = runTest {
        val jpeg = byteArrayOf(1)
        every { imageParts.image(jpeg) } returns mockk()
        coEvery { api.uploadAvatar(any(), any(), any()) } returns ProfileDto("uuid-1", "Alice")

        val result = runCatching { repository.uploadAvatar(jpeg) }

        assertTrue(result.exceptionOrNull() is IllegalStateException)
    }

    @Test
    fun `avatarUrl builds the URL for a ref`() = runTest {
        assertEquals("https://api.example.com/api/avatars/ref-9", repository.avatarUrl("ref-9"))
    }

    @Test
    fun `removeAvatar deletes the current device avatar`() = runTest {
        coEvery { api.deleteAvatar(any(), any()) } returns Unit

        repository.removeAvatar()

        coVerify {
            api.deleteAvatar("https://api.example.com/api/profile/avatar", "test-device-key")
        }
    }
}

private object AvatarFakeDeviceIdentity : DeviceIdentity {
    override val key: String = "test-device-key"
}
