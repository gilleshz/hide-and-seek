package fr.gshz.hideandseek.fake

import okhttp3.ResponseBody.Companion.toResponseBody
import retrofit2.HttpException
import retrofit2.Response

fun httpException(code: Int, body: String = ""): HttpException =
    HttpException(Response.error<Any>(code, body.toResponseBody(null)))
