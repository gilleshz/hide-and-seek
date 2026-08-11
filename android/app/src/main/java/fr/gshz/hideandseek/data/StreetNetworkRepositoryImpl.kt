package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.mapper.toDomain
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.domain.model.StreetNetwork
import fr.gshz.hideandseek.domain.repository.StreetNetworkRepository
import javax.inject.Inject

class StreetNetworkRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
) : StreetNetworkRepository {

    override suspend fun getStreetNetwork(roundUuid: String): StreetNetwork =
        api.getStreetNetwork(
            url = urlFor("/api/rounds/$roundUuid/street-network"),
        ).toDomain()

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path
}
