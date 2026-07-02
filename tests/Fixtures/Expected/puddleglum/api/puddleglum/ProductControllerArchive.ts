/* eslint-disable @typescript-eslint/no-unused-vars */
import axios, { AxiosRequestConfig } from 'axios';
import { Glum } from 'puddleglum';
import { transformToQueryString, PaginatedResponse } from 'puddleglum/utils';

export default class ProductControllerArchive {
  static async index(
    validationOnly: boolean = false,
    fieldToValidate: string = '',
    config: AxiosRequestConfig = {},
  ) {
    return axios.get(`/products/archive`, {
      headers: {
        Precognition: validationOnly,
        ...(fieldToValidate
          ? { 'Precognition-Validate-Only': fieldToValidate }
          : {}),
      },
      ...config,
    });
  }
}
