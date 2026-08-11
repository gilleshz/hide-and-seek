package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.StreetNetwork

interface StreetNetworkRepository {
    /** Hider-only: the subscriber token is the credential, attached by the OkHttp interceptor. */
    suspend fun getStreetNetwork(roundUuid: String): StreetNetwork
}
