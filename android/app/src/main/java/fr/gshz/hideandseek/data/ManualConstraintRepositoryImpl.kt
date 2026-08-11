package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.mapper.toDomain
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.AddManualConstraintRequest
import fr.gshz.hideandseek.domain.model.ConstraintMode
import fr.gshz.hideandseek.domain.model.ManualConstraint
import fr.gshz.hideandseek.domain.repository.ManualConstraintRepository
import javax.inject.Inject

class ManualConstraintRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
) : ManualConstraintRepository {

    override suspend fun getManualConstraints(roundUuid: String): List<ManualConstraint> =
        api.getManualConstraints(urlFor("/api/rounds/$roundUuid/possible-area-constraints"))
            .map { it.toDomain() }

    override suspend fun addManualConstraint(
        roundUuid: String,
        playerUuid: String,
        geoJson: String,
        mode: ConstraintMode,
        label: String?,
    ): ManualConstraint = api.addManualConstraint(
        url = urlFor("/api/rounds/$roundUuid/possible-area-constraints"),
        body = AddManualConstraintRequest(
            playerUuid = playerUuid,
            geoJson = geoJson,
            mode = mode.wireValue,
            label = label,
        ),
    ).toDomain()

    override suspend fun deleteManualConstraint(roundUuid: String, constraintUuid: String, playerUuid: String) {
        api.deleteManualConstraint(
            url = urlFor("/api/rounds/$roundUuid/possible-area-constraints/$constraintUuid"),
            playerUuid = playerUuid,
        )
    }

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path
}
