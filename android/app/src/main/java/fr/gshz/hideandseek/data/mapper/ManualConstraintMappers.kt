package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.ManualConstraintDto
import fr.gshz.hideandseek.domain.model.ConstraintMode
import fr.gshz.hideandseek.domain.model.ManualConstraint

fun ManualConstraintDto.toDomain() = ManualConstraint(
    uuid = uuid,
    mode = ConstraintMode.fromWireValueOrNull(mode) ?: ConstraintMode.Include,
    geoJson = geoJson,
    label = label.orEmpty(),
    createdByName = createdByName,
)
