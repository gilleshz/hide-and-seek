package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.model.ConstraintMode
import fr.gshz.hideandseek.domain.model.ManualConstraint
import fr.gshz.hideandseek.domain.repository.ManualConstraintRepository

class FakeManualConstraintRepository : ManualConstraintRepository {
    var constraints = mutableListOf<ManualConstraint>()
    var nextUuid = "constraint-1"

    data class AddCall(
        val roundUuid: String,
        val playerUuid: String,
        val geoJson: String,
        val mode: ConstraintMode,
        val label: String?,
    )

    val addCalls = mutableListOf<AddCall>()
    val deleteCalls = mutableListOf<Triple<String, String, String>>()

    override suspend fun getManualConstraints(roundUuid: String): List<ManualConstraint> = constraints.toList()

    override suspend fun addManualConstraint(
        roundUuid: String,
        playerUuid: String,
        geoJson: String,
        mode: ConstraintMode,
        label: String?,
    ): ManualConstraint {
        addCalls += AddCall(roundUuid, playerUuid, geoJson, mode, label)
        val constraint = ManualConstraint(nextUuid, mode, geoJson, label.orEmpty(), createdByName = null)
        constraints += constraint
        return constraint
    }

    override suspend fun deleteManualConstraint(roundUuid: String, constraintUuid: String, playerUuid: String) {
        deleteCalls += Triple(roundUuid, constraintUuid, playerUuid)
        constraints.removeAll { it.uuid == constraintUuid }
    }
}
