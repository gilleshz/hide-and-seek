package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.ClientConfigDto
import fr.gshz.hideandseek.domain.model.ClientConfig
import fr.gshz.hideandseek.domain.repository.ClientConfigRepository
import javax.inject.Inject

class ClientConfigRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
) : ClientConfigRepository {

    override suspend fun getClientConfig(apiUrl: String): ClientConfig {
        val dto = api.clientConfig(apiUrl.trimEnd('/') + "/api/client-config")
        return dto.toDomain()
    }
}

private fun ClientConfigDto.toDomain() = ClientConfig(
    stadiaApiKey = stadiaApiKey,
    thunderforestApiKey = thunderforestApiKey,
    maptilerApiKey = maptilerApiKey,
    mapStyleAvailable = mapStyleAvailable,
    availableStyles = availableStyles,
)
