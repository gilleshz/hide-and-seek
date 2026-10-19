package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.StationDeparturesDto
import kotlinx.serialization.json.Json
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class TransitDeparturesMapperTest {

    private val json = Json { ignoreUnknownKeys = true }

    @Test
    fun `the departures response deserializes and maps to domain`() {
        val dto = json.decodeFromString<StationDeparturesDto>(SAMPLE_JSON)

        val domain = dto.toDomain()

        assertEquals("S_49.0944_6.1354", domain.stationId)
        assertEquals("ALGER", domain.stationName)
        assertEquals("Europe/Paris", domain.timezone)
        assertEquals(1_760_000_000L, domain.serverNow)
        val group = domain.groups.single()
        assertEquals("1", group.routeId)
        assertEquals("L1", group.lineRef)
        assertEquals("B22E75", group.colour)
        assertEquals(0, group.directionId)
        assertTrue(group.realtime)
        val departure = group.departures.single()
        assertEquals(1_760_000_300L, departure.at)
        assertEquals(300, departure.inSeconds)
        assertEquals(60, departure.delaySeconds)
        assertEquals("La Corchade", departure.headsign)
    }

    @Test
    fun `a response without groups maps to an empty board`() {
        val dto = json.decodeFromString<StationDeparturesDto>("""{"stationId":"S_1","groups":[]}""")

        assertEquals(emptyList<Any>(), dto.toDomain().groups)
    }

    private companion object {
        const val SAMPLE_JSON = """
            {
              "stationId": "S_49.0944_6.1354",
              "uuid": "2b7f1c5e-0000-4000-8000-000000000001",
              "stationName": "ALGER",
              "timezone": "Europe/Paris",
              "serverNow": 1760000000,
              "groups": [
                {
                  "routeId": "1",
                  "lineRef": "L1",
                  "lineName": "MOULINS TOURNE...",
                  "colour": "B22E75",
                  "headsign": "La Corchade",
                  "directionId": 0,
                  "realtime": true,
                  "departures": [
                    {
                      "at": 1760000300,
                      "inSeconds": 300,
                      "realtime": true,
                      "delaySeconds": 60,
                      "headsign": "La Corchade"
                    }
                  ]
                }
              ]
            }
        """
    }
}
