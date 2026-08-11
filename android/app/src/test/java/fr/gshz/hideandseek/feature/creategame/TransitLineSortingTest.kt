package fr.gshz.hideandseek.feature.creategame

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class TransitLineSortingTest {

    @Test
    fun `numeric suffixes sort by value not lexicographically`() {
        val refs = listOf("T10", "T2", "T1", "T11", "T3")
        val sorted = refs.sortedWith { a, b -> compareNatural(a, b) }
        assertEquals(listOf("T1", "T2", "T3", "T10", "T11"), sorted)
    }

    @Test
    fun `letters sort before numbers when prefix matches`() {
        val refs = listOf("T3a", "T3", "T3b")
        val sorted = refs.sortedWith { a, b -> compareNatural(a, b) }
        assertEquals(listOf("T3", "T3a", "T3b"), sorted)
    }

    @Test
    fun `numeric chunks compared by length then lexicographically`() {
        val refs = listOf("100", "99", "1000", "50")
        val sorted = refs.sortedWith { a, b -> compareNatural(a, b) }
        assertEquals(listOf("50", "99", "100", "1000"), sorted)
    }

    @Test
    fun `different prefixes sort alphabetically`() {
        val refs = listOf("U2", "M1", "T3", "A10", "A2")
        val sorted = refs.sortedWith { a, b -> compareNatural(a, b) }
        assertEquals(listOf("A2", "A10", "M1", "T3", "U2"), sorted)
    }

    @Test
    fun `empty strings sort before non-empty`() {
        assertTrue(compareNatural("", "A") < 0)
        assertTrue(compareNatural("A", "") > 0)
        assertEquals(0, compareNatural("", ""))
    }

    @Test
    fun `equal strings return zero`() {
        assertEquals(0, compareNatural("T1", "T1"))
        assertEquals(0, compareNatural("U2", "U2"))
    }

    @Test
    fun `case insensitive sort`() {
        assertEquals(0, compareNatural("t1", "T1"))
        assertEquals(0, compareNatural("bus", "BUS"))
    }

    @Test
    fun `numeric-only strings sort by numeric value`() {
        val refs = listOf("42", "7", "100")
        val sorted = refs.sortedWith { a, b -> compareNatural(a, b) }
        assertEquals(listOf("7", "42", "100"), sorted)
    }

    @Test
    fun `mixed text and numbers handle edge cases`() {
        val refs = listOf("S41", "S9", "S100", "S41a", "S41b")
        val sorted = refs.sortedWith { a, b -> compareNatural(a, b) }
        assertEquals(listOf("S9", "S41", "S41a", "S41b", "S100"), sorted)
    }
}
