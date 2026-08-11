package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.ClientConfig

interface ClientConfigRepository {
    suspend fun getClientConfig(apiUrl: String): ClientConfig
}
