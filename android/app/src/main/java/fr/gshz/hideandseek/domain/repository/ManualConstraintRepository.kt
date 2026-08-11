package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.ConstraintMode
import fr.gshz.hideandseek.domain.model.ManualConstraint

interface ManualConstraintRepository {
    suspend fun getManualConstraints(roundUuid: String): List<ManualConstraint>

    suspend fun addManualConstraint(
        roundUuid: String,
        playerUuid: String,
        geoJson: String,
        mode: ConstraintMode,
        label: String?,
    ): ManualConstraint

    suspend fun deleteManualConstraint(roundUuid: String, constraintUuid: String, playerUuid: String)
}
