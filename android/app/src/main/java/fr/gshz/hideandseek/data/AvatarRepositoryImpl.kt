package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.device.DeviceIdentity
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.ImageParts
import fr.gshz.hideandseek.domain.model.Profile
import fr.gshz.hideandseek.domain.repository.AvatarRepository
import javax.inject.Inject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

class AvatarRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
    private val deviceIdentity: DeviceIdentity,
    private val imageParts: ImageParts,
) : AvatarRepository {

    override suspend fun getProfile(): Profile {
        val (base, name) = connectionIdentity()
        val dto = api.getProfile(base + PROFILE_PATH, name, deviceKey())
        return Profile(
            name = dto.name,
            avatarRef = dto.avatarRef,
            avatarUrl = dto.avatarRef?.let { "$base$AVATARS_PATH$it" },
        )
    }

    override suspend fun uploadAvatar(jpeg: ByteArray): String {
        val (base, name) = connectionIdentity()
        val dto = api.uploadAvatar(base + AVATAR_PATH, name, deviceKey(), imageParts.image(jpeg))
        return checkNotNull(dto.avatarRef) { "Server returned no avatar ref" }
    }

    override suspend fun avatarUrl(avatarRef: String): String {
        val config = connectionStore.current() ?: throw NotConnectedException()
        return config.apiUrl.trimEnd('/') + AVATARS_PATH + avatarRef
    }

    override suspend fun removeAvatar() {
        val (base, name) = connectionIdentity()
        api.deleteAvatar(base + AVATAR_PATH, name, deviceKey())
    }

    private suspend fun connectionIdentity(): Pair<String, String> {
        val config = connectionStore.current() ?: throw NotConnectedException()
        val name = connectionStore.currentPlayerName() ?: throw NotConnectedException()
        return config.apiUrl.trimEnd('/') to name
    }

    private suspend fun deviceKey(): String = withContext(Dispatchers.IO) { deviceIdentity.key }

    private companion object {
        const val PROFILE_PATH = "/api/profile"
        const val AVATAR_PATH = "/api/profile/avatar"
        const val AVATARS_PATH = "/api/avatars/"
    }
}
