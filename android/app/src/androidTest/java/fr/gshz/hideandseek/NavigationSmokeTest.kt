package fr.gshz.hideandseek

import android.net.Uri
import androidx.compose.material3.Text
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

class NavigationSmokeTest {

    @get:Rule
    val composeTestRule = createComposeRule()

    @Test
    fun appTheme_rendersWithoutCrash() {
        composeTestRule.setContent {
            AppTheme {
                Text("Hello Navigation Smoke")
            }
        }
        composeTestRule.onRoot().assertExists()
        composeTestRule.onNodeWithText("Hello Navigation Smoke").assertExists()
    }

    @Test
    fun destinations_allRouteConstantsAreNonBlank() {
        HideAndSeekDestinations::class.java.declaredFields
            .filter { it.type == String::class.java }
            .filter { it.name != "INSTANCE" }
            .forEach { field ->
                val value = field.get(null) as? String
                assertFalse(
                    "Route constant ${field.name} is blank",
                    value.isNullOrBlank(),
                )
            }
    }

    @Test
    fun destinations_argumentRoutesContainCurlyBraces() {
        val argumentRoutes = listOf(
            HideAndSeekDestinations.CONNECT,
            HideAndSeekDestinations.LOBBY,
            HideAndSeekDestinations.MAP,
            HideAndSeekDestinations.CHAT,
        )
        argumentRoutes.forEach { route ->
            assertTrue(
                "Argument route '$route' should contain '{'",
                route.contains("{"),
            )
            assertTrue(
                "Argument route '$route' should contain '}'",
                route.contains("}"),
            )
        }
    }

    @Test
    fun destinations_simpleRoutesHaveNoArguments() {
        val simpleRoutes = listOf(
            HideAndSeekDestinations.HOME,
            HideAndSeekDestinations.CREATE_GAME,
        )
        simpleRoutes.forEach { route ->
            assertFalse(
                "Simple route '$route' should not contain '{'",
                route.contains("{"),
            )
            assertFalse(
                "Simple route '$route' should not contain '?'",
                route.contains("?"),
            )
        }
    }

    @Test
    fun connectRoute_withNullErrorKey_returnsBaseRoute() {
        val result = HideAndSeekDestinations.connectRoute(null)
        assertTrue(result == "connect")
    }

    @Test
    fun connectRoute_withBlankErrorKey_returnsBaseRoute() {
        val result = HideAndSeekDestinations.connectRoute("")
        assertTrue(result == "connect")
    }

    @Test
    fun connectRoute_withErrorKeyOnly_returnsRouteWithErrorKey() {
        val result = HideAndSeekDestinations.connectRoute("join.password_invalid")
        assertTrue(result == "connect?errorKey=join.password_invalid")
    }

    @Test
    fun connectRoute_percentEncodesAmpersandInErrorKey() {
        val route = HideAndSeekDestinations.connectRoute("join.password_invalid&x")

        assertTrue(route == "connect?errorKey=join.password_invalid%26x")
        assertEquals(
            listOf("join.password_invalid&x"),
            extractArg(route, HideAndSeekDestinations.CONNECT_ERROR_KEY_ARG),
        )
    }

    @Test
    fun joinGameRoute_withNullCode_returnsBaseRoute() {
        val result = HideAndSeekDestinations.joinGameRoute(null)
        assertTrue(result == "joinGame")
    }

    @Test
    fun joinGameRoute_withBlankCode_returnsBaseRoute() {
        val result = HideAndSeekDestinations.joinGameRoute("")
        assertTrue(result == "joinGame")
    }

    @Test
    fun joinGameRoute_withCode_returnsRouteWithCode() {
        val result = HideAndSeekDestinations.joinGameRoute("ABC123")
        assertTrue(result == "joinGame?code=ABC123")
    }

    @Test
    fun joinGameRoute_withErrorKeyOnly_returnsRouteWithErrorKey() {
        val result = HideAndSeekDestinations.joinGameRoute(errorKey = "join.password_invalid")
        assertTrue(result == "joinGame?errorKey=join.password_invalid")
    }

    @Test
    fun joinGameRoute_withAllArgs_returnsRouteWithAllArgsInOrder() {
        val result = HideAndSeekDestinations.joinGameRoute("game-1", "join.password_invalid")
        assertTrue(result == "joinGame?code=game-1&errorKey=join.password_invalid")
    }

    @Test
    fun joinGameRoute_withBlankArgs_returnsBaseRoute() {
        val result = HideAndSeekDestinations.joinGameRoute("", "")
        assertTrue(result == "joinGame")
    }

    private fun extractArg(route: String, arg: String): List<String> =
        Uri.parse(route).getQueryParameters(arg)

    @Test
    fun lobbyRoute_embedsGameUuid() {
        val result = HideAndSeekDestinations.lobbyRoute("550e8400-e29b-41d4-a716-446655440000")
        assertTrue(result == "lobby/550e8400-e29b-41d4-a716-446655440000")
    }

    @Test
    fun mapRoute_embedsGameUuid() {
        val result = HideAndSeekDestinations.mapRoute("550e8400-e29b-41d4-a716-446655440000")
        assertTrue(result == "map/550e8400-e29b-41d4-a716-446655440000")
    }

    @Test
    fun chatRoute_embedsGameUuid() {
        val result = HideAndSeekDestinations.chatRoute("550e8400-e29b-41d4-a716-446655440000")
        assertTrue(result == "chat/550e8400-e29b-41d4-a716-446655440000")
    }
}
