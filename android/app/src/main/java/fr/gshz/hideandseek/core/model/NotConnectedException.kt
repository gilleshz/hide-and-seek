package fr.gshz.hideandseek.core.model

import java.io.IOException

/**
 * Thrown when a repository needs the connection config but none is saved
 * (e.g. Disconnect tapped mid-poll). Extends IOException so the existing
 * catch (IOException) funnels in ViewModels and the tracking service
 * handle it gracefully instead of crashing the coroutine.
 */
class NotConnectedException : IOException("Not connected to a server")
