package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.device.DeviceIdentity
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.ImageParts
import fr.gshz.hideandseek.data.remote.dto.ProfileDto
import fr.gshz.hideandseek.data.remote.dto.ProfileNameRequest
import fr.gshz.hideandseek.domain.model.Profile
import fr.gshz.hideandseek.domain.repository.AvatarRepository
import javax.inject.Inject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody

class AvatarRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
    private val deviceIdentity: DeviceIdentity,
    private val imageParts: ImageParts,
    private val json: Json,
) : AvatarRepository {

    override suspend fun bootstrap(displayName: String): Profile =
        toProfile(api.bootstrapProfile(profileUrl(), deviceKey(), ProfileNameRequest(displayName)))

    override suspend fun rename(displayName: String): Profile =
        toProfile(
            api.renameProfile(
                profileUrl(),
                deviceKey(),
                json.encodeToString(ProfileNameRequest(displayName)).toRequestBody(MERGE_PATCH_MEDIA_TYPE),
            ),
        )

    override suspend fun getProfile(): Profile = toProfile(api.getProfile(profileUrl(), deviceKey()))

    override suspend fun uploadAvatar(jpeg: ByteArray): Profile {
        val profile = toProfile(api.uploadAvatar(avatarEndpoint(), deviceKey(), imageParts.image(jpeg)))
        checkNotNull(profile.avatarRef) { "Server returned no avatar ref" }
        return profile
    }

    override suspend fun avatarUrl(avatarRef: String): String = apiBase() + AVATARS_PATH + avatarRef

    override suspend fun removeAvatar() {
        api.deleteAvatar(avatarEndpoint(), deviceKey())
    }

    private suspend fun toProfile(dto: ProfileDto): Profile {
        val base = apiBase()
        return Profile(
            uuid = dto.uuid,
            name = dto.name,
            avatarRef = dto.avatarRef,
            avatarUrl = dto.avatarRef?.let { base + AVATARS_PATH + it },
        )
    }

    private suspend fun profileUrl(): String = apiBase() + PROFILE_PATH

    private suspend fun avatarEndpoint(): String = apiBase() + AVATAR_PATH

    private suspend fun apiBase(): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/')

    private suspend fun deviceKey(): String = withContext(Dispatchers.IO) { deviceIdentity.key }

    private companion object {
        const val PROFILE_PATH = "/api/profile"
        const val AVATAR_PATH = "/api/profile/avatar"
        const val AVATARS_PATH = "/api/avatars/"
        val MERGE_PATCH_MEDIA_TYPE = "application/merge-patch+json".toMediaType()
    }
}
