package fr.gshz.hideandseek.feature.question

import fr.gshz.hideandseek.domain.model.FeatureType
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.QuestionCategory
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class QuestionOptionsTest {

    private val borderTypes = listOf(
        FeatureType.BorderInternational,
        FeatureType.Border1st,
        FeatureType.Border2nd,
    )

    @Test
    fun `measuring offers the three border types`() {
        val measuring = availableFeatureTypes(QuestionCategory.Measuring, GameSize.Large)

        assertTrue(measuring.containsAll(borderTypes))
    }

    @Test
    fun `matching does not offer the border types`() {
        val matching = availableFeatureTypes(QuestionCategory.Matching, GameSize.Large)

        assertTrue(borderTypes.none { it in matching })
    }

    @Test
    fun `the border wire values match the backend enum`() {
        assertEquals("border_international", FeatureType.BorderInternational.wireValue)
        assertEquals("border_admin_1st", FeatureType.Border1st.wireValue)
        assertEquals("border_admin_2nd", FeatureType.Border2nd.wireValue)
    }

    @Test
    fun `border wire values round-trip through fromWireValue`() {
        borderTypes.forEach { type ->
            assertEquals(type, FeatureType.fromWireValue(type.wireValue))
        }
        assertFalse(borderTypes.any { FeatureType.fromWireValue(it.wireValue) == null })
    }
}
