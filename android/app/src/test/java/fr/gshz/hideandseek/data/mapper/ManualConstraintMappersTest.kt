package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.ManualConstraintDto
import fr.gshz.hideandseek.domain.model.ConstraintMode
import kotlinx.serialization.json.Json
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Test

class ManualConstraintMappersTest {

    private val json = Json { ignoreUnknownKeys = true }

    @Test
    fun `a payload with omitted nullable fields decodes without crashing`() {
        val dto = json.decodeFromString<ManualConstraintDto>(
            """{"uuid":"c-1","mode":"include","geoJson":"geo-1"}""",
        )

        val constraint = dto.toDomain()

        assertEquals("c-1", constraint.uuid)
        assertEquals(ConstraintMode.Include, constraint.mode)
        assertEquals("geo-1", constraint.geoJson)
        assertEquals("", constraint.label)
        assertNull(constraint.createdByName)
    }

    @Test
    fun `a full payload maps every field to the domain`() {
        val dto = ManualConstraintDto(
            uuid = "c-2",
            mode = "exclude",
            geoJson = "geo-2",
            source = "manual",
            label = "Old town",
            createdByName = "Bob",
        )

        val constraint = dto.toDomain()

        assertEquals("c-2", constraint.uuid)
        assertEquals(ConstraintMode.Exclude, constraint.mode)
        assertEquals("Old town", constraint.label)
        assertEquals("Bob", constraint.createdByName)
    }
}
